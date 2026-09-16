<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_stripe_enhanced\Unit;

use CommerceGuys\Intl\Formatter\CurrencyFormatterInterface;
use Drupal\commerce_order\Adjustment;
use Drupal\commerce_order\AdjustmentTypeManager;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\commerce_price\MinorUnitsConverterInterface;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\commerce_price\Price;
use Drupal\commerce_stripe_enhanced\PaymentBreakdown;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Covers the arithmetic Stripe refuses an intent over.
 *
 * Stripe rejects any intent whose breakdown does not sum to its amount, and a
 * rejected intent takes the payment step down for that customer - so the rule
 * that matters is not what a correct order produces, but that anything which
 * does not add up produces nothing at all. Every case here is an order shape
 * the builder must decline rather than send.
 *
 * Unit rather than kernel: this is a sum over adjustments, and mocking the
 * order is cheaper than installing commerce to build one. What it cannot cover
 * - that the values reach Stripe, and that Stripe accepts them - is covered by
 * the sandbox, not by a test that would have to call the API to prove it.
 */
#[Group('aai')]
#[Group('commerce_stripe_enhanced')]
final class PaymentBreakdownTest extends UnitTestCase {

  /**
   * The breakdown builder under test.
   */
  protected PaymentBreakdown $breakdown;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $converter = $this->createMock(MinorUnitsConverterInterface::class);
    $converter->method('toMinorUnits')
      ->willReturnCallback(fn(Price $price) => (int) round(((float) $price->getNumber()) * 100));

    $formatter = $this->createMock(CurrencyFormatterInterface::class);
    $formatter->method('format')
      ->willReturnCallback(fn($number, $currency) => '$' . number_format((float) $number, 2));

    // Adjustment validates its type against the plugin manager as it is
    // constructed, so building one needs a container to ask.
    $adjustment_types = $this->createMock(AdjustmentTypeManager::class);
    $types = ['tax', 'shipping', 'shipping_promotion', 'promotion', 'fee', 'custom'];
    $adjustment_types->method('getDefinitions')->willReturn(array_combine(
      $types,
      array_map(fn(string $type) => ['id' => $type, 'label' => $type], $types),
    ));
    $container = new ContainerBuilder();
    $container->set('plugin.manager.commerce_adjustment_type', $adjustment_types);
    \Drupal::setContainer($container);

    $this->breakdown = new PaymentBreakdown($converter, $formatter);
  }

  /**
   * An order's items, tax and shipping become the fields Stripe asks for.
   */
  public function testBreakdownOfItemsTaxAndShipping(): void {
    $order = $this->order(
      [$this->orderItem('Andes', '1', '1592', 'ANDES')],
      [
        $this->adjustment('tax', 'Tax', '117.41'),
        $this->adjustment('shipping', 'Shipping', '200'),
      ],
    );

    $details = $this->breakdown->amountDetails($order, 190941);

    $this->assertSame([
      [
        'product_name' => 'Andes',
        'unit_cost' => 159200,
        'quantity' => 1,
        'product_code' => 'ANDES',
      ],
    ], $details['line_items']);
    $this->assertSame(11741, $details['tax']['total_tax_amount']);
    $this->assertSame(20000, $details['shipping']['amount']);
    $this->assertArrayNotHasKey('discount_amount', $details);
  }

  /**
   * A total the pieces do not add up to sends nothing.
   *
   * The amount is the order's balance, which is not the order's total once
   * anything has already been paid against it - a gift card, a deposit, a
   * partial refund. There is no honest breakdown of a part payment, so there
   * is none.
   */
  public function testPartialPaymentSendsNoBreakdown(): void {
    $order = $this->order(
      [$this->orderItem('Andes', '1', '1592')],
      [$this->adjustment('shipping', 'Shipping', '200')],
    );

    $this->assertNull($this->breakdown->amountDetails($order, 100000));
  }

  /**
   * A quantity Stripe cannot express sends nothing.
   *
   * Stripe takes an integer quantity. A rug sold by the square foot, or any
   * item measured rather than counted, has no whole number to send.
   */
  public function testFractionalQuantitySendsNoBreakdown(): void {
    $order = $this->order(
      [$this->orderItem('Broadloom', '2.5', '100')],
      [],
    );

    $this->assertNull($this->breakdown->amountDetails($order, 25000));
  }

  /**
   * A charge Stripe has no field for becomes a line of its own.
   *
   * Stripe names shipping, tax and a discount. Everything else a site adds -
   * a handling fee, a surcharge, a site's own adjustment type - is still
   * money the customer paid, and dropping it would break the sum.
   */
  public function testUnnamedChargeBecomesLineItem(): void {
    $order = $this->order(
      [$this->orderItem('Andes', '1', '1592')],
      [$this->adjustment('fee', 'Handling', '10')],
    );

    $details = $this->breakdown->amountDetails($order, 160200);

    $this->assertSame([
      ['product_name' => 'Andes', 'unit_cost' => 159200, 'quantity' => 1],
      ['product_name' => 'Handling', 'unit_cost' => 1000, 'quantity' => 1],
    ], $details['line_items']);
  }

  /**
   * Promotions of any type total into the discount Stripe expects.
   */
  public function testPromotionsBecomeDiscount(): void {
    $order = $this->order(
      [$this->orderItem('Andes', '1', '1592')],
      [
        $this->adjustment('promotion', '10% off', '-159.20'),
        $this->adjustment('shipping', 'Shipping', '200'),
        $this->adjustment('shipping_promotion', 'Free shipping', '-200'),
      ],
    );

    // 1592.00 item + 200.00 shipping - 159.20 - 200.00 promotions.
    $details = $this->breakdown->amountDetails($order, 143280);

    $this->assertSame(35920, $details['discount_amount']);
    $this->assertSame(20000, $details['shipping']['amount']);
  }

  /**
   * An adjustment already inside a price is not counted twice.
   *
   * An included adjustment describes what a price is made of rather than
   * adding to it, so the line item already carries it. Tax is the exception
   * Stripe wants stated, and commerce shows it for the same reason.
   */
  public function testIncludedAdjustmentIsNotAddedAgain(): void {
    $order = $this->order(
      [$this->orderItem('Andes', '1', '1592')],
      [$this->adjustment('shipping_promotion', 'Surcharge', '150', TRUE)],
    );

    $details = $this->breakdown->amountDetails($order, 159200);

    $this->assertSame([
      ['product_name' => 'Andes', 'unit_cost' => 159200, 'quantity' => 1],
    ], $details['line_items']);
  }

  /**
   * The metadata a person refunding the payment reads.
   *
   * One entry per kind of charge, named by its adjustment type, so a refund
   * can be worked out without opening the order. Tax is listed even when it
   * was included in the prices, as the order summary lists it.
   */
  public function testMetadataNamesEachKindOfCharge(): void {
    $order = $this->order(
      [$this->orderItem('Andes', '1', '1592')],
      [
        $this->adjustment('tax', 'Tax', '117.41'),
        $this->adjustment('shipping', 'Shipping', '200'),
        $this->adjustment('shipping', 'White Glove: Elevation', '75'),
        $this->adjustment('promotion', '10% off', '-159.20'),
      ],
      new Price('1592', 'USD'),
    );

    $this->assertSame([
      'subtotal' => '$1,592.00',
      'tax' => '$117.41',
      'shipping' => '$275.00',
      'promotion' => '$-159.20',
    ], $this->breakdown->metadata($order));
  }

  /**
   * Builds an order that carries the given items and adjustments.
   *
   * @param \Drupal\commerce_order\Entity\OrderItemInterface[] $items
   *   The order items.
   * @param \Drupal\commerce_order\Adjustment[] $adjustments
   *   The adjustments, as collectAdjustments() would return them.
   * @param \Drupal\commerce_price\Price|null $subtotal
   *   The subtotal, where the test reads metadata.
   *
   * @return \Drupal\commerce_order\Entity\OrderInterface
   *   The order.
   */
  protected function order(array $items, array $adjustments, ?Price $subtotal = NULL): OrderInterface {
    $order = $this->createMock(OrderInterface::class);
    $order->method('getItems')->willReturn($items);
    $order->method('collectAdjustments')->willReturn($adjustments);
    $order->method('getSubtotalPrice')->willReturn($subtotal);
    $order->method('collectProfiles')->willReturn([]);
    // Shipments are commerce_shipping's, and an order without them is the
    // shape this class must not assume away.
    $order->method('hasField')->willReturn(FALSE);
    $order->method('get')->willReturn($this->createMock(FieldItemListInterface::class));
    $order->method('getStore')->willReturn(NULL);

    return $order;
  }

  /**
   * Builds an order item.
   *
   * @param string $label
   *   The item's label.
   * @param string $quantity
   *   The quantity, as commerce stores it.
   * @param string $unit_price
   *   The unit price.
   * @param string|null $sku
   *   The SKU of the purchased entity, where the item has one.
   *
   * @return \Drupal\commerce_order\Entity\OrderItemInterface
   *   The order item.
   */
  protected function orderItem(string $label, string $quantity, string $unit_price, ?string $sku = NULL): OrderItemInterface {
    $item = $this->createMock(OrderItemInterface::class);
    $item->method('label')->willReturn($label);
    $item->method('getQuantity')->willReturn($quantity);
    $item->method('getUnitPrice')->willReturn(new Price($unit_price, 'USD'));

    $purchased_entity = NULL;
    if ($sku !== NULL) {
      $purchased_entity = $this->createMock(ProductVariationInterface::class);
      $purchased_entity->method('getSku')->willReturn($sku);
    }
    $item->method('getPurchasedEntity')->willReturn($purchased_entity);

    return $item;
  }

  /**
   * Builds an adjustment.
   *
   * @param string $type
   *   The adjustment type.
   * @param string $label
   *   The label.
   * @param string $amount
   *   The amount.
   * @param bool $included
   *   Whether the adjustment is already inside the price.
   *
   * @return \Drupal\commerce_order\Adjustment
   *   The adjustment.
   */
  protected function adjustment(string $type, string $label, string $amount, bool $included = FALSE): Adjustment {
    return new Adjustment([
      'type' => $type,
      'label' => $label,
      'amount' => new Price($amount, 'USD'),
      'included' => $included,
    ]);
  }

}
