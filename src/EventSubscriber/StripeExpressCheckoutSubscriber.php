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
 * Where the number lives differs by wallet, and both shapes are real: Apple Pay
 * returns it only in the payment method's billing details, Google Pay in both
 * that and the shipping address. So both are read, shipping first. The format
 * differs too - Apple Pay sends 6128030704 where Google Pay sends
 * +1 650-555-5555 - so it is normalised, or the field holds whichever shape the
 * customer's wallet happened to use.
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
    // The shipping phone first: this lands on the shipping profile, so the
    // number wanted is the one for the delivery, and a wallet that supplies one
    // is naming it. Google Pay does. Apple Pay returns no shipping phone at
    // all, so for it the billing details are the only source - which is why the
    // fallback is not optional.
    //
    // billing_details on the charge, not under payment_method - a charge's
    // payment_method is an id string unless it was expanded, and indexing a
    // string by name finds nothing. That was the bug: every Apple Pay order
    // lost its phone number, silently, and Google Pay hid it for three orders
    // by satisfying the first lookup.
    $phone = $attributes['shipping']['phone']
      ?? $attributes['billing_details']['phone']
      ?? NULL;
    // And under an expanded payment method, for a caller that passes one.
    if ($phone === NULL && is_array($attributes['payment_method'] ?? NULL)) {
      $phone = $attributes['payment_method']['billing_details']['phone'] ?? NULL;
    }

    if ($phone !== NULL && trim((string) $phone) !== '') {
      $profile->set($field_name, $this->formatPhone((string) $phone));
    }
  }

  /**
   * Normalises a wallet's phone number to +c ccc-ccc-cccc.
   *
   * Only where the digits are unambiguously a North American number - ten
   * digits, or eleven beginning with the country code 1. Anything else is
   * returned as the wallet sent it: a number we cannot parse with confidence is
   * better stored unformatted than stamped with a country code it may not have,
   * which would make it undialable rather than merely untidy.
   *
   * @param string $phone
   *   The number as the wallet supplied it.
   *
   * @return string
   *   The formatted number.
   */
  protected function formatPhone(string $phone): string {
    $digits = preg_replace('/\D+/', '', $phone) ?? '';
    if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
      $digits = substr($digits, 1);
    }
    elseif (strlen($digits) !== 10) {
      return trim($phone);
    }

    return sprintf(
      '+1 %s-%s-%s',
      substr($digits, 0, 3),
      substr($digits, 3, 3),
      substr($digits, 6)
    );
  }

}
