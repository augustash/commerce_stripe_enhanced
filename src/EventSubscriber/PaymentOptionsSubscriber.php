<?php

namespace Drupal\commerce_stripe_enhanced\EventSubscriber;

use Drupal\commerce_payment\Event\FilterPaymentOptionsEvent;
use Drupal\commerce_stripe\Plugin\Commerce\PaymentGateway\StripePaymentElementInterface;
use Drupal\commerce_stripe_enhanced\ExpressMethods;
use Drupal\commerce_stripe_enhanced\PaymentMethodLimits;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Withholds a payment option Stripe would refuse the order's amount for.
 *
 * Stripe enforces a minimum and maximum per method, and refuses to create the
 * intent outside them. That refusal is not a soft failure: it happens as the
 * payment step builds, so the customer who picked the option gets a broken
 * step rather than a message, and there is nothing they can do about it.
 *
 * A method the order's total is outside of is therefore not an option at all,
 * and should not be offered as one. Stripe's own express element already works
 * this way - it simply does not raise a button it cannot take - and
 * automatic_payment_methods resolves the same way on an intent. Explicitly
 * naming the method types, which is what makes one payment radio mean one
 * method, is what loses that, so it has to be put back here.
 *
 * Only where every method behind the option is out of range. An option whose
 * gateway also offers something acceptable stays, and the intent narrowing
 * drops the unacceptable method from it.
 *
 * @see \Drupal\commerce_stripe_enhanced\PaymentMethodLimits
 */
class PaymentOptionsSubscriber implements EventSubscriberInterface {

  /**
   * Constructs the subscriber.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\commerce_stripe_enhanced\ExpressMethods $expressMethods
   *   The express methods helper, for its name conversions.
   * @param \Drupal\commerce_stripe_enhanced\PaymentMethodLimits $limits
   *   The payment method limits.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ExpressMethods $expressMethods,
    protected PaymentMethodLimits $limits,
  ) {}

  /**
   * {@inheritdoc}
   *
   * Spelled out rather than referenced through PaymentEvents, for the same
   * reason as this module's other subscribers: a class name resolved while the
   * container compiles can bring a site down on an environment where the
   * module owning it is present but not yet installed.
   */
  public static function getSubscribedEvents(): array {
    return [
      'commerce_payment.filter_payment_options' => 'onFilterPaymentOptions',
    ];
  }

  /**
   * Removes the options this order's total puts out of Stripe's reach.
   *
   * @param \Drupal\commerce_payment\Event\FilterPaymentOptionsEvent $event
   *   The filter payment options event.
   */
  public function onFilterPaymentOptions(FilterPaymentOptionsEvent $event): void {
    $order = $event->getOrder();
    $total = $order->getTotalPrice();
    if (!$total) {
      return;
    }

    $gateway_storage = $this->entityTypeManager->getStorage('commerce_payment_gateway');
    $options = $event->getPaymentOptions();
    $kept = [];
    foreach ($options as $id => $option) {
      $gateway = $gateway_storage->load($option->getPaymentGatewayId());
      $plugin = $gateway?->getPlugin();
      // Every other gateway keeps its own rules; these limits are Stripe's.
      if (!$plugin instanceof StripePaymentElementInterface) {
        $kept[$id] = $option;
        continue;
      }

      if ($this->limits->accepted($this->optionMethods($option, $plugin), $total)) {
        $kept[$id] = $option;
      }
    }

    if (count($kept) !== count($options)) {
      $event->setPaymentOptions($kept);
    }
  }

  /**
   * Names the Stripe methods one payment option can be paid with.
   *
   * @param \Drupal\commerce_payment\PaymentOption $option
   *   The payment option.
   * @param \Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\PaymentGatewayInterface $plugin
   *   Its gateway plugin.
   *
   * @return string[]
   *   Stripe method names, snake_case.
   */
  protected function optionMethods($option, $plugin): array {
    // A method already on file is that one method, whatever else its gateway
    // is configured to offer.
    if ($option->getPaymentMethodId()) {
      $payment_method = $this->entityTypeManager
        ->getStorage('commerce_payment_method')
        ->load($option->getPaymentMethodId());
      return $payment_method
        ? [$this->expressMethods->stripeNameFromPluginId($payment_method->bundle())]
        : [];
    }

    // An option naming one type is that type; otherwise the gateway's whole
    // list, since the element will offer all of them behind this one radio.
    $types = $option->getPaymentMethodTypeId()
      ? [$option->getPaymentMethodTypeId()]
      : array_keys($plugin->getPaymentMethodTypes());

    return array_map(
      fn (string $type) => $this->expressMethods->stripeNameFromPluginId($type),
      $types
    );
  }

}
