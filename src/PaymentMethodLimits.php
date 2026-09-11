<?php

namespace Drupal\commerce_stripe_enhanced;

use Drupal\commerce_price\Calculator;
use Drupal\commerce_price\Price;

/**
 * Holds the order amounts Stripe will accept each payment method for.
 *
 * Stripe enforces a minimum and maximum per method and exposes them nowhere:
 * a payment method configuration carries only `available` and a display
 * preference, with no amount or currency limits on it, and there is no other
 * endpoint to ask. The only place the numbers appear is the rejection message
 * on an intent Stripe has already refused - "Amount must be no less than
 * $35.00 USD for the Affirm payment method" - which is far too late, because
 * refusing to create the intent takes the payment step down for that customer.
 *
 * So they are kept here, in code, measured by probing the API. They change
 * rarely, and when one does this is the single place to edit. Config would put
 * the same numbers in every consuming site's export, to be hand-maintained per
 * site and drift per site; these are Stripe's rules, not a site's decision.
 *
 * A site's own rule is a different thing and belongs in configuration, as a
 * condition on the gateway - "we do not offer financing under $50" is a
 * business call, and sits above whatever Stripe would technically allow.
 *
 * Only the methods whose limits have been measured are listed. An unlisted
 * method is unconstrained here, which is the safe default: it behaves exactly
 * as it does without this module.
 */
class PaymentMethodLimits {

  /**
   * Order amount ranges, keyed by Stripe method name and currency code.
   *
   * Measured against the Stripe API on 2026-09-11. A missing bound means
   * Stripe enforces none.
   */
  const LIMITS = [
    'affirm' => [
      'USD' => ['min' => '35.00', 'max' => '30000.00'],
    ],
  ];

  /**
   * Whether Stripe would accept this method for this order total.
   *
   * @param string $method
   *   The Stripe method name, snake_case - 'affirm', 'card'.
   * @param \Drupal\commerce_price\Price|null $total
   *   The order total.
   *
   * @return bool
   *   FALSE only where a limit is known and the total falls outside it.
   */
  public function accepts(string $method, ?Price $total): bool {
    if (!$total) {
      return TRUE;
    }
    $limits = self::LIMITS[$method][$total->getCurrencyCode()] ?? NULL;
    if (!$limits) {
      return TRUE;
    }

    $number = $total->getNumber();
    if (isset($limits['min']) && Calculator::compare($number, $limits['min']) < 0) {
      return FALSE;
    }
    if (isset($limits['max']) && Calculator::compare($number, $limits['max']) > 0) {
      return FALSE;
    }

    return TRUE;
  }

  /**
   * Filters a list of Stripe method names to those this total is within.
   *
   * @param string[] $methods
   *   Stripe method names, snake_case.
   * @param \Drupal\commerce_price\Price|null $total
   *   The order total.
   *
   * @return string[]
   *   The methods Stripe would accept for this total.
   */
  public function accepted(array $methods, ?Price $total): array {
    return array_values(array_filter(
      $methods,
      fn (string $method) => $this->accepts($method, $total)
    ));
  }

}
