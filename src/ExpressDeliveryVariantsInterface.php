<?php

namespace Drupal\commerce_stripe_enhanced;

use Drupal\commerce_shipping\ShippingRate;

/**
 * Lets a shipping method offer its options as rates a wallet can show.
 *
 * A wallet collects delivery through a sheet, and a sheet takes a flat list of
 * rates - a name, a price, one line of text. It cannot ask a question. So any
 * question a site normally asks *about* a delivery method - is the address
 * above
 * one floor, does the building need a certificate of insurance, is a Saturday
 * acceptable - has nowhere to go unless each answer becomes its own rate.
 *
 * Which questions exist, what they cost and how an answer is recorded are
 * things only the shipping method knows. There is no commerce contract to read
 * them from, and guessing at one - sniffing field names, or config keys ending
 * in _amount - would be a detection that quietly breaks on the next method. So
 * a method that has options declares them by implementing this, and one that
 * does not is left exactly as it is.
 *
 * The same plugin reads the answer back, through its own selectRate(): it names
 * the service ids here and interprets them there, so the two halves cannot
 * drift apart.
 *
 * Only consulted inside an express checkout request. The site's own checkout
 * asks these questions properly, with a form, and a form is the better place
 * when there is room for one.
 *
 * @see \Drupal\commerce_stripe_enhanced\ExpressCheckoutContext
 * @see \Drupal\commerce_shipping\Plugin\Commerce\ShippingMethod\ShippingMethodInterface::selectRate()
 */
interface ExpressDeliveryVariantsInterface {

  /**
   * Builds the rates standing in for this method's options.
   *
   * Each returned rate is offered to the wallet beside the one it came from, so
   * price it for the answers it represents - a customer picks on the number
   * shown, and options that all cost the same when they do not is a sheet that
   * misleads. Where a surcharge is normally applied elsewhere, such as an order
   * processor reading a field, that elsewhere has to stand down for these; the
   * service id is what tells it to.
   *
   * @param \Drupal\commerce_shipping\ShippingRate $rate
   *   The rate this method calculated, to build the variants from.
   *
   * @return \Drupal\commerce_shipping\ShippingRate[]
   *   Rates for the option combinations worth offering, each with a service id
   *   this method's selectRate() can read back. Empty for an order whose
   *   options do not apply.
   */
  public function getExpressDeliveryVariants(ShippingRate $rate): array;

}
