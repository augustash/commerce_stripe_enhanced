<?php

namespace Drupal\commerce_stripe_enhanced;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Entity\PaymentGatewayInterface;
use Drupal\commerce_payment\PaymentMethodTypeManager;
use Drupal\commerce_stripe\Plugin\Commerce\PaymentGateway\StripePaymentElementInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Tells the express wallets apart from the payment methods in the pane.
 *
 * Nothing in commerce_stripe marks a payment method as express-capable. The
 * method type plugins carry an id and a label and nothing else - no flag, no
 * interface method - and the only enumeration of what Stripe's Express Checkout
 * Element supports is a hardcoded #options array inside the gateway's own
 * configuration form, which is not reachable from outside it.
 *
 * So the two lists that decide where a method appears are independent, and
 * nothing ties them: the express element shows whatever is ticked in the
 * gateway's express_checkout.allowed_payment_method_types, while the pane shows
 * whatever the gateway names in payment_method_types. Enable a method in both
 * and the customer is offered it twice - once as a one-tap button above the
 * form, once as a radio inside it, having already declined it.
 *
 * This class supplies the missing distinction, derived rather than listed:
 *
 * - Which methods this order's express element will actually offer, read from
 *   the gateway that renders it.
 * - Which of those are card-riding. Apple Pay and Google Pay are not payment
 *   methods at all - they are a card presented by a wallet - and the tell is
 *   that neither has a payment method type plugin, while every other
 *   express-capable method has one. It follows that narrowing an intent to
 *   'card' does not remove them, so they have to be turned off through the
 *   Payment Element's own wallets option instead.
 * - Which are standalone methods, which an intent's payment_method_types does
 *   govern.
 *
 * Deriving it from plugin presence rather than a list of names means the
 * classification follows commerce_stripe: the day Apple Pay becomes a payment
 * method type in its own right, it stops being treated as card-riding, which is
 * the correct answer at that point.
 */
class ExpressMethods {

  /**
   * Every method Stripe's Express Checkout Element can offer.
   *
   * Mirrors $supported_payment_method_types in StripePaymentElement's
   * configuration form. Carried because that array is local to a form build,
   * and needed only for the "leave empty to allow all" case - a gateway with
   * anything ticked tells us its own list exactly, and that is the common case.
   *
   * Spelled as the element's own option keys, which are camelCase and differ
   * from both the Stripe API's method names and commerce's plugin ids.
   *
   * @see \Drupal\commerce_stripe\Plugin\Commerce\PaymentGateway\StripePaymentElement::buildConfigurationForm()
   */
  const EXPRESS_CAPABLE = [
    'paypal',
    'amazonPay',
    'applePay',
    'googlePay',
    'klarna',
    'link',
  ];

  /**
   * Constructs the express methods helper.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\commerce_payment\PaymentMethodTypeManager $paymentMethodTypeManager
   *   The payment method type plugin manager.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ConfigFactoryInterface $configFactory,
    protected PaymentMethodTypeManager $paymentMethodTypeManager,
  ) {}

  /**
   * Finds the gateway whose express element this order would be offered.
   *
   * The first Stripe Payment Element gateway available to the order. More than
   * one can be enabled - a second instance offering a different Stripe method
   * is how this module expects Affirm to be configured - but they share one
   * Stripe account, so the wallets any of them can raise are the same wallets.
   * Offering a second row of them would ask the same question twice.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   *
   * @return \Drupal\commerce_payment\Entity\PaymentGatewayInterface|null
   *   The gateway, or NULL if none of the order's gateways is a Stripe one.
   */
  public function expressGateway(OrderInterface $order): ?PaymentGatewayInterface {
    $storage = $this->entityTypeManager->getStorage('commerce_payment_gateway');
    foreach ($storage->loadMultipleForOrder($order) as $payment_gateway) {
      if ($payment_gateway->getPlugin() instanceof StripePaymentElementInterface) {
        return $payment_gateway;
      }
    }

    return NULL;
  }

  /**
   * Whether this order's customer is offered the express wallets anywhere.
   *
   * Either upstream's cart placement or ours in checkout. The distinction
   * matters because every suppression here is justified only by the wallet
   * being offered somewhere better - a site running no express element must
   * keep its wallets in the pane, where they are the only way to reach one.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   *
   * @return bool
   *   TRUE if an express element is offered.
   */
  public function isExpressOffered(OrderInterface $order): bool {
    $gateway = $this->expressGateway($order);
    if (!$gateway) {
      return FALSE;
    }
    if ($this->configFactory->get('commerce_stripe_enhanced.settings')->get('express.enabled')) {
      return TRUE;
    }

    $express = $gateway->getPlugin()->getExpressCheckout();
    return !empty($express['enable_on_cart']);
  }

  /**
   * Names the methods this order's express element will offer.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   *
   * @return string[]
   *   Express Checkout Element option keys, camelCase. Empty when no express
   *   element is offered.
   */
  public function enabledMethods(OrderInterface $order): array {
    if (!$this->isExpressOffered($order)) {
      return [];
    }
    $gateway = $this->expressGateway($order);
    $express = $gateway->getPlugin()->getExpressCheckout();
    $allowed = array_keys(array_filter($express['allowed_payment_method_types'] ?? []));

    // Upstream's own rule: nothing ticked means every type Stripe will show,
    // because it then passes no paymentMethods option at all.
    return $allowed ?: self::EXPRESS_CAPABLE;
  }

  /**
   * Picks out the methods that are a card behind a wallet.
   *
   * @param string[] $methods
   *   Express Checkout Element option keys.
   *
   * @return string[]
   *   Those with no payment method type plugin of their own, in the same
   *   spelling - which is also the spelling the Payment Element's wallets
   *   option uses.
   */
  public function cardRiding(array $methods): array {
    return array_values(array_filter(
      $methods,
      fn (string $method) => !$this->paymentMethodTypeManager->hasDefinition($this->pluginId($method))
    ));
  }

  /**
   * Picks out the methods that stand on their own.
   *
   * @param string[] $methods
   *   Express Checkout Element option keys.
   *
   * @return string[]
   *   Those with a payment method type plugin, named as the Stripe API names
   *   them - which is the spelling an intent's payment_method_types uses.
   */
  public function standalone(array $methods): array {
    $standalone = [];
    foreach ($methods as $method) {
      if ($this->paymentMethodTypeManager->hasDefinition($this->pluginId($method))) {
        $standalone[] = $this->stripeName($method);
      }
    }

    return $standalone;
  }

  /**
   * Names the Stripe method a commerce payment method type stands for.
   *
   * Upstream names its method type plugins after the Stripe method with a
   * stripe_ prefix - stripe_affirm for affirm, stripe_us_bank_account for
   * us_bank_account - so dropping the prefix is the whole translation, for all
   * ten of them.
   *
   * @param string $plugin_id
   *   A payment method type plugin id.
   *
   * @return string
   *   The Stripe method name, snake_case.
   */
  public function stripeNameFromPluginId(string $plugin_id): string {
    return str_starts_with($plugin_id, 'stripe_')
      ? substr($plugin_id, strlen('stripe_'))
      : $plugin_id;
  }

  /**
   * Converts an element option key to the Stripe API's name for the method.
   *
   * @param string $method
   *   An Express Checkout Element option key, camelCase.
   *
   * @return string
   *   The Stripe method name, snake_case.
   */
  protected function stripeName(string $method): string {
    return strtolower(preg_replace('/([a-z])([A-Z])/', '$1_$2', $method));
  }

  /**
   * Converts an element option key to commerce_stripe's plugin id.
   *
   * Upstream names its method type plugins after the Stripe method with a
   * stripe_ prefix - stripe_amazon_pay for amazon_pay, stripe_link for link.
   *
   * @param string $method
   *   An Express Checkout Element option key, camelCase.
   *
   * @return string
   *   The payment method type plugin id.
   */
  protected function pluginId(string $method): string {
    return 'stripe_' . $this->stripeName($method);
  }

}
