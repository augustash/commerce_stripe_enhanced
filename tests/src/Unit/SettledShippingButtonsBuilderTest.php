<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_stripe_enhanced\Unit;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Entity\PaymentGatewayInterface;
use Drupal\commerce_stripe\ExpressCheckoutButtonsBuilderInterface;
use Drupal\commerce_stripe_enhanced\ExpressMethods;
use Drupal\commerce_stripe_enhanced\SettledShippingButtonsBuilder;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * A settled order's wallet sheet asks for no address.
 *
 * A sheet that asks for one re-rates the delivery on whatever it is given,
 * replacing the shipping the order already carries. Unit rather than kernel:
 * upstream only builds a shippable sheet with commerce_shipping deciding the
 * order ships, and what is ours is the rewrite of the settings it produces.
 */
#[Group('aai')]
#[Group('commerce_stripe_enhanced')]
final class SettledShippingButtonsBuilderTest extends UnitTestCase {

  /**
   * A settled order's sheet is told the order does not ship.
   */
  public function testSettledSheetCollectsNoShipping(): void {
    $settings = $this->build(TRUE);

    $this->assertFalse($settings['isShippable']);
    $this->assertArrayNotHasKey('shippingAddressRequired', $settings['onClickEventOptions']);
    $this->assertArrayNotHasKey('allowedShippingCountries', $settings['onClickEventOptions']);
    $this->assertArrayNotHasKey('shippingRates', $settings['onClickEventOptions']);
    // Everything else the wallet asks for is still asked.
    $this->assertTrue($settings['onClickEventOptions']['emailRequired']);
  }

  /**
   * Any other order's sheet is upstream's, untouched.
   */
  public function testOpenSheetUnchanged(): void {
    $this->assertSame($this->upstreamSettings(), $this->build(FALSE));
  }

  /**
   * Builds the element and returns its one sheet's settings.
   */
  protected function build(bool $settled): array {
    $inner = $this->createMock(ExpressCheckoutButtonsBuilderInterface::class);
    $inner->method('build')->willReturn([
      '#attached' => ['drupalSettings' => ['commerceStripeExpressCheckoutElement' => ['stripe-express-checkout-element' => $this->upstreamSettings()]]],
    ]);
    $express_methods = $this->createMock(ExpressMethods::class);
    $express_methods->method('isShippingSettled')->willReturn($settled);

    $element = (new SettledShippingButtonsBuilder($inner, $express_methods))
      ->build($this->createMock(OrderInterface::class), $this->createMock(PaymentGatewayInterface::class));

    return $element['#attached']['drupalSettings']['commerceStripeExpressCheckoutElement']['stripe-express-checkout-element'];
  }

  /**
   * The settings upstream builds for a shippable order.
   */
  protected function upstreamSettings(): array {
    return [
      'isShippable' => TRUE,
      'onClickEventOptions' => [
        'emailRequired' => TRUE,
        'shippingAddressRequired' => TRUE,
        'allowedShippingCountries' => ['US'],
        'shippingRates' => [['id' => 'no-shipping-address', 'amount' => 0]],
      ],
    ];
  }

}
