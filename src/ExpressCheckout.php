<?php

namespace Drupal\commerce_stripe_enhanced;

use Drupal\commerce_checkout\Plugin\Commerce\CheckoutFlow\CheckoutFlowInterface;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_stripe\ExpressCheckoutButtonsBuilderInterface;
use Drupal\commerce_stripe\Plugin\Commerce\PaymentGateway\StripePaymentElementInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Offers the express wallets at the head of checkout.
 *
 * Upstream injects the Express Checkout element on the cart form alone, gated
 * on the gateway's enable_on_cart setting. A customer can reach checkout
 * without ever passing through a cart - a "buy now" link, a restored session, a
 * cart block that goes straight to checkout - and for them the wallets simply
 * do not exist. Same builder service, placed where that customer will meet it.
 *
 * Placement is not cosmetic. Confirming a wallet calls clearOrderCheckoutData()
 * and moves the order to the review step, because a wallet supplies its own
 * name, address and delivery choice and the module treats that as
 * authoritative. That is right when the wallet is the entry point and
 * destructive anywhere else: offered after the address form, it discards the
 * address the customer just typed. So it belongs at the head of the first step
 * a customer sees, where taking it means never filling that form.
 */
class ExpressCheckout {

  use StringTranslationTrait;

  /**
   * Constructs the express checkout helper.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\commerce_stripe\ExpressCheckoutButtonsBuilderInterface $buttonsBuilder
   *   The Express Checkout buttons builder.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ConfigFactoryInterface $configFactory,
    protected ExpressCheckoutButtonsBuilderInterface $buttonsBuilder,
  ) {}

  /**
   * Adds the express wallets to a checkout form, if this is their step.
   *
   * @param array $form
   *   The checkout flow form, by reference.
   * @param \Drupal\commerce_checkout\Plugin\Commerce\CheckoutFlow\CheckoutFlowInterface $flow
   *   The checkout flow plugin the form belongs to.
   * @param string|null $step_id
   *   The step being built.
   */
  public function attach(array &$form, CheckoutFlowInterface $flow, ?string $step_id): void {
    $settings = $this->configFactory->get('commerce_stripe_enhanced.settings')->get('express');
    if (empty($settings['enabled']) || $step_id === NULL) {
      return;
    }
    if ($step_id !== ($settings['step'] ?: $this->firstStepId($flow))) {
      return;
    }

    $order = $flow->getOrder();
    // Nothing to pay for, nothing to offer.
    if (!$order || $order->getTotalPrice() === NULL || !$order->getTotalPrice()->isPositive()) {
      return;
    }
    $payment_gateway = $this->expressGateway($order);
    if (!$payment_gateway) {
      return;
    }

    // A titled section rather than loose buttons: the wallets are one of two
    // ways to start, and the heading is what makes that a decision rather than
    // decoration above the form. A container with a heading rather than a
    // fieldset, which carries a legend that themes style as a form label
    // instead of as a section title beside the panes below it.
    $form['stripe_express_checkout'] = [
      '#type' => 'container',
      '#weight' => -100,
      '#attributes' => [
        'class' => ['checkout-pane', 'checkout-pane-express'],
      ],
      'title' => [
        '#type' => 'html_tag',
        '#tag' => 'h6',
        '#value' => $settings['title'],
      ],
      'buttons' => $this->buttonsBuilder->build($order, $payment_gateway),
    ];

    // Names the choice the two sections represent. Without it they read as two
    // things to do in order rather than two ways to start, and the wallets look
    // like a step someone skipped rather than one they declined.
    if (!empty($settings['divider_label'])) {
      $form['stripe_express_checkout_divider'] = [
        '#type' => 'html_tag',
        '#tag' => 'div',
        '#value' => $settings['divider_label'],
        '#weight' => -99,
        '#attributes' => [
          'class' => ['checkout-divider'],
        ],
      ];
    }
  }

  /**
   * Names the first step a customer actually lands on.
   *
   * The flow's own step order, minus the steps nobody is sent to: the hidden
   * ones a flow declares for internal routing, 'complete', which is the end of
   * checkout rather than the start, and 'login', which is a gate in front of
   * checkout rather than a step of it - a site that would rather offer a wallet
   * in place of signing in can name that step in config.
   *
   * Derived rather than defaulted to 'order_information' so that a flow which
   * renamed or reordered its steps still gets the wallets in the right place -
   * that rename is the most common thing a custom flow does.
   *
   * @param \Drupal\commerce_checkout\Plugin\Commerce\CheckoutFlow\CheckoutFlowInterface $flow
   *   The checkout flow plugin.
   *
   * @return string|null
   *   The step id, or NULL if the flow has no step to put them on.
   */
  protected function firstStepId(CheckoutFlowInterface $flow): ?string {
    $steps = $flow->getVisibleSteps();
    foreach ($steps as $step_id => $step) {
      if ($step_id === 'login' || $step_id === 'complete' || !empty($step['hidden'])) {
        continue;
      }
      return $step_id;
    }

    return NULL;
  }

  /**
   * Finds the gateway whose express element should be offered.
   *
   * The first Stripe Payment Element gateway available to this order. More than
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
  protected function expressGateway(OrderInterface $order) {
    $storage = $this->entityTypeManager->getStorage('commerce_payment_gateway');
    foreach ($storage->loadMultipleForOrder($order) as $payment_gateway) {
      if ($payment_gateway->getPlugin() instanceof StripePaymentElementInterface) {
        return $payment_gateway;
      }
    }

    return NULL;
  }

}
