<?php

namespace Drupal\commerce_stripe_enhanced\EventSubscriber;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_stripe\Event\PaymentIntentCreateEvent;
use Drupal\commerce_stripe_enhanced\ExpressMethods;
use Drupal\commerce_stripe_enhanced\PaymentMethodLimits;
use Drupal\commerce_stripe_enhanced\Plugin\Commerce\PaymentGateway\StripePaymentElement;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Shapes the Stripe payment intent to the choice the customer actually made.
 *
 * Two jobs, both about what an intent is allowed to offer.
 *
 * Stripe excludes any method that cannot be saved for later — Affirm, Klarna,
 * WeChat Pay — from an intent that asks for setup_future_usage across the whole
 * intent. Asking for it on the card method alone leaves the rest untouched.
 *
 * And at checkout the intent is narrowed to card. Every radio on the payment
 * step is a payment method with its own form, so an unfiltered intent turned
 * the Credit Card option into a second menu offering Affirm and two wallets —
 * a category pretending to be a choice, and Affirm twice over, since it is also
 * its own radio.
 *
 * @see \Drupal\commerce_stripe_enhanced\Plugin\Commerce\PaymentGateway\StripePaymentElement
 *   which puts the value back where the parent gateway expects to read it.
 */
class StripePaymentIntentSubscriber implements EventSubscriberInterface {

  /**
   * Constructs the subscriber.
   *
   * @param \Drupal\commerce_stripe_enhanced\ExpressMethods $expressMethods
   *   The express methods helper.
   * @param \Drupal\commerce_stripe_enhanced\PaymentMethodLimits $limits
   *   The payment method limits.
   */
  public function __construct(
    protected ExpressMethods $expressMethods,
    protected PaymentMethodLimits $limits,
  ) {}

  /**
   * {@inheritdoc}
   *
   * Spelled out rather than using StripeEvents::PAYMENT_INTENT_CREATE. This
   * runs while the container compiles, and Drupal only registers a module's
   * namespace once that module is enabled — so referencing the constant makes
   * the container unbuildable on any environment where commerce_stripe is in
   * the codebase but not yet installed. That is every deploy that lands this
   * code ahead of its config import, and it deadlocks: nothing can boot, so
   * nothing can run the import that would enable the module.
   *
   * Everything below this point is safe to reference normally — PHP resolves
   * type hints and bodies on call, and the only caller is the event itself.
   */
  public static function getSubscribedEvents(): array {
    return [
      'commerce_stripe.payment_intent.create' => 'onIntentCreate',
    ];
  }

  /**
   * Narrows the intent to card and scopes setup_future_usage to it.
   *
   * @param \Drupal\commerce_stripe\Event\PaymentIntentCreateEvent $event
   *   The intent create event.
   */
  public function onIntentCreate(PaymentIntentCreateEvent $event): void {
    $order = $event->getOrder();
    $gateway = $order->get('payment_gateway')->entity;
    // Other gateways keep the stock behaviour.
    if (!$gateway || !$gateway->getPlugin() instanceof StripePaymentElement) {
      return;
    }

    $attributes = $event->getIntentAttributes();

    // The express element builds its own intent through this same method, and
    // its whole point is offering several wallets at once - Amazon Pay is a
    // payment method type in its own right, so narrowing to card would empty
    // the row. ExpressCheckoutController flags the order before it asks for an
    // intent, which is what makes the two cases separable here.
    if (!$order->getData('stripe_express_checkout', FALSE)) {
      $attributes['payment_method_types'] = $this->intentMethodTypes($gateway->getPlugin(), $order);
      // Stripe rejects an intent carrying both, and the parent sets this by
      // default in createPaymentIntent().
      unset($attributes['automatic_payment_methods']);
    }

    if (!empty($attributes['setup_future_usage'])) {
      $attributes['payment_method_options']['card']['setup_future_usage'] = $attributes['setup_future_usage'];
      unset($attributes['setup_future_usage']);
    }

    $event->setIntentAttributes($attributes);
  }

  /**
   * Names the Stripe methods this intent should offer in the pane.
   *
   * Read off the gateway rather than mapped here, so a second instance is a
   * config change: which methods an intent may offer is exactly what the
   * gateway's payment_method_types already says. commerce_stripe names those
   * plugins after the Stripe method with a `stripe_` prefix - stripe_affirm for
   * affirm, stripe_us_bank_account for us_bank_account - so dropping the prefix
   * is the whole translation, for all ten of them.
   *
   * Minus whatever the express element is already offering. The two lists are
   * configured independently and nothing upstream ties them, so a method
   * turned on in both is presented twice - and the second time is not a second
   * radio: a Stripe Payment Element gateway renders one radio per instance
   * however many method types it carries, and the extra methods surface as
   * tabs inside the element. So the intent is the only lever, and a wallet
   * ticked for express arrives here as a tab under "Credit Card", asking for
   * something the customer already walked past above the form.
   *
   * @param \Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\PaymentGatewayInterface $plugin
   *   The gateway plugin.
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order the intent is for.
   *
   * @return string[]
   *   Stripe payment method type names.
   */
  protected function intentMethodTypes($plugin, OrderInterface $order): array {
    $types = [];
    foreach (array_keys($plugin->getPaymentMethodTypes()) as $plugin_id) {
      $types[] = $this->expressMethods->stripeNameFromPluginId($plugin_id);
    }

    $express = $this->expressMethods->standalone(
      $this->expressMethods->enabledMethods($order)
    );
    // Except whatever the customer has actually selected. The radio offering a
    // method for the first time is gone, but a method already on file keeps
    // its own - so an intent that dropped its type would render a selected
    // option that cannot be confirmed, which is worse than offering it twice.
    $selected = $order->get('payment_method')->entity;
    if ($selected) {
      $express = array_diff($express, [
        $this->expressMethods->stripeNameFromPluginId($selected->bundle()),
      ]);
    }
    $types = array_values(array_diff($types, $express)) ?: $types;

    // And minus anything Stripe will not take this order's amount for. Naming
    // it would have Stripe refuse the intent outright, which breaks the step
    // rather than just hiding a method - PaymentOptionsSubscriber has already
    // withheld any option that had nothing else to offer, so what is dropped
    // here is a method sitting alongside one that works.
    $accepted = $this->limits->accepted($types, $order->getTotalPrice());
    $types = $accepted ?: $types;

    // A gateway with nothing configured would otherwise send an empty list,
    // which Stripe rejects outright.
    return $types ?: ['card'];
  }

}
