<?php

namespace Drupal\commerce_stripe_enhanced;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Entity\PaymentGatewayInterface;
use Drupal\commerce_stripe\ExpressCheckoutButtonsBuilderInterface;

/**
 * Builds a payment-only wallet sheet for an order whose shipping is settled.
 *
 * Upstream decides whether the sheet asks for an address from the order being
 * shippable, and a shippable sheet re-rates the delivery on every address it
 * is given - replacing whatever the order carried. An order whose shipping is
 * settled is told it is not shippable, so the sheet asks for nothing and the
 * amount it charges is the order's total, shipping included.
 *
 * A decorator rather than an alter, because both placements build through this
 * service - upstream's on the cart and this module's in checkout - and neither
 * offers a seam of its own.
 *
 * @see \Drupal\commerce_stripe_enhanced\ExpressMethods::isShippingSettled()
 */
class SettledShippingButtonsBuilder implements ExpressCheckoutButtonsBuilderInterface {

  /**
   * Constructs the decorator.
   *
   * @param \Drupal\commerce_stripe\ExpressCheckoutButtonsBuilderInterface $inner
   *   The decorated builder.
   * @param \Drupal\commerce_stripe_enhanced\ExpressMethods $expressMethods
   *   The express methods helper.
   */
  public function __construct(
    protected ExpressCheckoutButtonsBuilderInterface $inner,
    protected ExpressMethods $expressMethods,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function build(OrderInterface $order, PaymentGatewayInterface $payment_gateway): array {
    $element = $this->inner->build($order, $payment_gateway);
    if (!$this->expressMethods->isShippingSettled($order)) {
      return $element;
    }

    foreach ($element['#attached']['drupalSettings']['commerceStripeExpressCheckoutElement'] ?? [] as $element_id => $settings) {
      $settings['isShippable'] = FALSE;
      unset(
        $settings['onClickEventOptions']['shippingAddressRequired'],
        $settings['onClickEventOptions']['allowedShippingCountries'],
        $settings['onClickEventOptions']['shippingRates'],
      );
      $element['#attached']['drupalSettings']['commerceStripeExpressCheckoutElement'][$element_id] = $settings;
    }

    return $element;
  }

  /**
   * Passes anything else upstream adds straight through.
   */
  public function __call(string $method, array $arguments): mixed {
    return $this->inner->{$method}(...$arguments);
  }

}
