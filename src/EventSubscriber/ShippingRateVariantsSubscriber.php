<?php

namespace Drupal\commerce_stripe_enhanced\EventSubscriber;

use Drupal\commerce_shipping\Event\ShippingRatesEvent;
use Drupal\commerce_stripe_enhanced\ExpressCheckoutContext;
use Drupal\commerce_stripe_enhanced\ExpressDeliveryVariantsInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Adds a shipping method's option rates while a wallet is asking.
 *
 * The mechanism only; what the options are belongs to the shipping method,
 * which
 * declares them by implementing ExpressDeliveryVariantsInterface. A method that
 * does not implement it is untouched, so this is inert on a site with nothing
 * to
 * offer.
 *
 * @see \Drupal\commerce_stripe_enhanced\ExpressDeliveryVariantsInterface
 */
class ShippingRateVariantsSubscriber implements EventSubscriberInterface {

  /**
   * Constructs the subscriber.
   *
   * @param \Drupal\commerce_stripe_enhanced\ExpressCheckoutContext $expressContext
   *   Tells whether a wallet is driving this request.
   */
  public function __construct(protected ExpressCheckoutContext $expressContext) {}

  /**
   * {@inheritdoc}
   *
   * Spelled out rather than referenced through ShippingEvents, for the same
   * reason as this module's other subscribers: a class name resolved while the
   * container compiles can bring a site down on an environment where the module
   * owning it is present but not yet installed. commerce_shipping is optional
   * here - a site selling downloads has no shipping at all - which makes that a
   * live possibility rather than a precaution.
   */
  public static function getSubscribedEvents(): array {
    return [
      'commerce_shipping.rates' => ['onCalculateRates', -100],
    ];
  }

  /**
   * Offers each option combination as a rate of its own.
   *
   * Runs late, so the rates it builds from are the ones every other subscriber
   * has finished adjusting - a site-wide surcharge added to the base rate is
   * inherited by the variants rather than missed by them.
   *
   * @param \Drupal\commerce_shipping\Event\ShippingRatesEvent $event
   *   The shipping rates event.
   */
  public function onCalculateRates(ShippingRatesEvent $event): void {
    if (!$this->expressContext->isExpressRequest()) {
      return;
    }
    $plugin = $event->getShippingMethod()->getPlugin();
    if (!$plugin instanceof ExpressDeliveryVariantsInterface) {
      return;
    }

    $rates = $event->getRates();
    $expanded = [];
    foreach ($rates as $rate) {
      $expanded[$rate->getId()] = $rate;
      foreach ($plugin->getExpressDeliveryVariants($rate) as $variant) {
        $expanded[$variant->getId()] = $variant;
      }
    }

    if (count($expanded) !== count($rates)) {
      $event->setRates($expanded);
    }
  }

}
