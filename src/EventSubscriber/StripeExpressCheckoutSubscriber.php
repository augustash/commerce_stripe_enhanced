<?php

namespace Drupal\commerce_stripe_enhanced\EventSubscriber;

use Drupal\commerce_stripe\Event\ExpressCheckoutShippingProfileAlterEvent;
use Drupal\Core\Config\ConfigFactoryInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Carries the wallet's phone number onto the shipping profile.
 *
 * The wallets ask for a phone number - collect_phone_number is on, and Apple
 * Pay and Google Pay both present it - but commerce_stripe writes only the
 * address onto the profile and drops everything else the wallet returned. Where
 * the profile requires a phone, an express order then arrives without the one
 * piece of contact information a carrier needs to arrange the delivery, and
 * nothing complains: the field is required on the form, and express never shows
 * the form.
 *
 * Which field receives it is config, because commerce ships no phone field on
 * the customer profile - the site that added one names it in
 * commerce_stripe_enhanced.settings:shipping_phone_field. Left empty, there is
 * nothing to write and this does nothing.
 *
 * @see \Drupal\commerce_stripe\Plugin\Commerce\PaymentGateway\StripePaymentElement::updateShippingProfile()
 */
class StripeExpressCheckoutSubscriber implements EventSubscriberInterface {

  /**
   * Constructs the subscriber.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   */
  public function __construct(protected ConfigFactoryInterface $configFactory) {}

  /**
   * {@inheritdoc}
   *
   * Spelled out rather than referenced through the StripeEvents constant. This
   * runs while the container compiles, and Drupal only registers a module's
   * namespace once that module is enabled - so referencing the constant makes
   * the container unbuildable on any environment where commerce_stripe is
   * present but not yet installed, which deadlocks the deploy that would
   * install it.
   */
  public static function getSubscribedEvents(): array {
    return [
      'commerce_stripe.express_checkout_shipping_profile_alter' => 'onShippingProfileAlter',
    ];
  }

  /**
   * Copies the wallet's phone number onto the profile.
   *
   * @param \Drupal\commerce_stripe\Event\ExpressCheckoutShippingProfileAlterEvent $event
   *   The shipping profile alter event.
   */
  public function onShippingProfileAlter(ExpressCheckoutShippingProfileAlterEvent $event): void {
    $field_name = $this->configFactory
      ->get('commerce_stripe_enhanced.settings')
      ->get('shipping_phone_field');
    if (empty($field_name)) {
      return;
    }

    $profile = $event->getShippingProfile();
    if (!$profile->hasField($field_name)) {
      return;
    }

    $attributes = $event->getChargeAttributes();
    // Stripe returns it against the shipping address the wallet supplied. The
    // payment method's billing details carry it too, for a wallet that
    // collected a billing phone but no shipping one.
    $phone = $attributes['shipping']['phone']
      ?? $attributes['payment_method']['billing_details']['phone']
      ?? NULL;

    if ($phone !== NULL && trim((string) $phone) !== '') {
      $profile->set($field_name, trim((string) $phone));
    }
  }

}
