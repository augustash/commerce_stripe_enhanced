<?php

namespace Drupal\commerce_stripe_enhanced;

use CommerceGuys\Intl\Formatter\CurrencyFormatterInterface;
use Drupal\commerce_order\Adjustment;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_price\MinorUnitsConverterInterface;
use Drupal\commerce_price\Price;

/**
 * Tells Stripe what a payment is made of.
 *
 * To Stripe a Payment Element payment is an amount and nothing more, so a
 * refund taken in its dashboard is a number typed against a number: keeping
 * the shipping on a returned item means opening the order to find out what
 * part of the total the shipping was. Two places carry the breakdown, because
 * Stripe reads them for different things.
 *
 * amount_details is where Stripe asks for it - line items, shipping, tax,
 * discount - and hands it to card networks, Klarna and PayPal. The dashboard
 * shows none of it. Stripe also refuses any change to an intent's amount that
 * does not restate it, which is why OrderPaymentIntentSubscriber is replaced.
 *
 * Metadata is what the dashboard shows, beside the payment, where the refund
 * is decided. It is written once the payment is recorded, when the order can no
 * longer change under it.
 */
class PaymentBreakdown {

  /**
   * Stripe's cap on line items per intent.
   */
  const MAX_LINE_ITEMS = 200;

  /**
   * Constructs the breakdown builder.
   *
   * @param \Drupal\commerce_price\MinorUnitsConverterInterface $minorUnitsConverter
   *   The minor units converter.
   * @param \CommerceGuys\Intl\Formatter\CurrencyFormatterInterface $currencyFormatter
   *   The currency formatter.
   */
  public function __construct(
    protected MinorUnitsConverterInterface $minorUnitsConverter,
    protected CurrencyFormatterInterface $currencyFormatter,
  ) {}

  /**
   * Builds amount_details for an intent charging the given amount.
   *
   * Stripe rejects an intent whose breakdown does not sum to its amount, and a
   * rejected intent takes the payment step down. So the sum is checked here
   * first, and anything that does not add up - a partial payment, a fractional
   * quantity, a price in fractions of a cent - sends no breakdown rather than
   * a wrong one. The payment goes through either way.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   * @param int $amount
   *   The intent amount, in minor units.
   *
   * @return array|null
   *   The amount_details parameter, or NULL when the order does not add up to
   *   the amount.
   */
  public function amountDetails(OrderInterface $order, int $amount): ?array {
    $line_items = [];
    $sum = 0;
    foreach ($order->getItems() as $order_item) {
      $quantity = $order_item->getQuantity();
      if ((int) $quantity != $quantity || (int) $quantity < 1) {
        return NULL;
      }
      $line_item = [
        'product_name' => mb_substr($order_item->label(), 0, 1024),
        'unit_cost' => $this->minorUnitsConverter->toMinorUnits($order_item->getUnitPrice()),
        'quantity' => (int) $quantity,
      ];
      $purchased_entity = $order_item->getPurchasedEntity();
      $sku = $purchased_entity && method_exists($purchased_entity, 'getSku') ? (string) $purchased_entity->getSku() : '';
      // Stripe refuses a longer code outright, and a truncated SKU names a
      // different product.
      if ($sku !== '' && strlen($sku) <= 12) {
        $line_item['product_code'] = $sku;
      }
      $line_items[] = $line_item;
      $sum += $line_item['unit_cost'] * $line_item['quantity'];
    }

    $tax = $shipping = $discount = 0;
    foreach ($this->adjustments($order) as $adjustment) {
      $minor = $this->minorUnitsConverter->toMinorUnits($adjustment->getAmount());
      if ($adjustment->getType() === 'tax') {
        $tax += $minor;
      }
      elseif ($adjustment->getType() === 'shipping' && $minor >= 0) {
        $shipping += $minor;
      }
      elseif ($minor < 0) {
        $discount -= $minor;
      }
      // A fee or any other charge Stripe has no field for is still something
      // the customer paid for, and leaving it out breaks the sum.
      elseif ($minor > 0) {
        $line_items[] = [
          'product_name' => mb_substr($adjustment->getLabel(), 0, 1024),
          'unit_cost' => $minor,
          'quantity' => 1,
        ];
      }
      $sum += $minor;
    }

    if ($sum !== $amount || !$line_items || count($line_items) > self::MAX_LINE_ITEMS) {
      return NULL;
    }

    $details = ['line_items' => $line_items];
    if ($tax > 0) {
      $details['tax']['total_tax_amount'] = $tax;
    }
    if ($discount > 0) {
      $details['discount_amount'] = $discount;
    }
    if ($shipping > 0 || $this->hasShipments($order)) {
      $details['shipping']['amount'] = $shipping;
      if ($to = $this->postalCode($order->collectProfiles()['shipping'] ?? NULL)) {
        $details['shipping']['to_postal_code'] = $to;
      }
      if ($from = $this->postalCode($order->getStore())) {
        $details['shipping']['from_postal_code'] = $from;
      }
    }
    return $details;
  }

  /**
   * Builds the metadata a person refunding the payment reads.
   *
   * One entry per kind of charge, named by its adjustment type, so the keys
   * are the same on every payment and a refund can be worked out at a glance:
   * subtotal, shipping, tax, promotion. Mirrors the order summary the customer
   * saw - included adjustments are left out, being already inside the prices,
   * except tax.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   *
   * @return array
   *   Metadata keyed by name, values formatted for reading.
   */
  public function metadata(OrderInterface $order): array {
    $metadata = [];
    if ($subtotal = $order->getSubtotalPrice()) {
      $metadata['subtotal'] = $this->format($subtotal);
    }

    $totals = [];
    foreach ($order->collectAdjustments() as $adjustment) {
      if ($adjustment->isIncluded() && $adjustment->getType() !== 'tax') {
        continue;
      }
      $type = $adjustment->getType();
      $totals[$type] = isset($totals[$type]) ? $totals[$type]->add($adjustment->getAmount()) : $adjustment->getAmount();
    }
    foreach ($totals as $type => $total) {
      $metadata[$type] = $this->format($total);
    }

    if ($carrier = $this->carrier($order)) {
      $metadata['shipping_method'] = $carrier;
    }
    return $metadata;
  }

  /**
   * Names the shipping method, for the intent's shipping.carrier.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   *
   * @return string|null
   *   The shipping method labels, or NULL for an order with no shipments.
   */
  public function carrier(OrderInterface $order): ?string {
    if (!$this->hasShipments($order)) {
      return NULL;
    }
    $labels = [];
    foreach ($order->get('shipments')->referencedEntities() as $shipment) {
      if ($method = $shipment->getShippingMethod()) {
        $labels[$method->label()] = $method->label();
      }
    }
    return $labels ? mb_substr(implode(', ', $labels), 0, 500) : NULL;
  }

  /**
   * Gets the adjustments that change what the customer pays.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   *
   * @return \Drupal\commerce_order\Adjustment[]
   *   The order's and its items' adjustments, less those already inside a
   *   price.
   */
  protected function adjustments(OrderInterface $order): array {
    return array_filter(
      $order->collectAdjustments(),
      fn(Adjustment $adjustment) => !$adjustment->isIncluded(),
    );
  }

  /**
   * Whether the order is shipped.
   */
  protected function hasShipments(OrderInterface $order): bool {
    return $order->hasField('shipments') && !$order->get('shipments')->isEmpty();
  }

  /**
   * Reads a postal code in the shape Stripe accepts.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface|null $entity
   *   A profile or store carrying an address field.
   *
   * @return string|null
   *   Up to ten letters, digits and hyphens, or NULL.
   */
  protected function postalCode($entity): ?string {
    if (!$entity || !$entity->hasField('address') || $entity->get('address')->isEmpty()) {
      return NULL;
    }
    $code = preg_replace('/[^A-Za-z0-9-]/', '', (string) $entity->get('address')->first()->getPostalCode());
    return $code !== '' && strlen($code) <= 10 ? $code : NULL;
  }

  /**
   * Formats a price for reading.
   */
  protected function format(Price $price): string {
    return $this->currencyFormatter->format($price->getNumber(), $price->getCurrencyCode());
  }

}
