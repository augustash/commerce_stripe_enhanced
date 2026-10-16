<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_stripe_enhanced\Unit;

use Drupal\commerce_stripe\Event\ExpressCheckoutShippingProfileAlterEvent;
use Drupal\commerce_stripe_enhanced\EventSubscriber\StripeExpressCheckoutSubscriber;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\profile\Entity\ProfileInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * The wallet's phone fills a delivery's phone, and never replaces one.
 *
 * A wallet that collected the address built the profile itself and left the
 * phone empty. One paying for an order whose shipping was settled beforehand
 * finds the number given for that delivery, which is the one the carrier
 * should call.
 */
#[Group('aai')]
#[Group('commerce_stripe_enhanced')]
final class WalletPhoneTest extends UnitTestCase {

  /**
   * An empty phone takes the wallet's.
   */
  public function testFillsEmptyPhone(): void {
    $profile = $this->profile(TRUE);
    $profile->expects($this->once())->method('set')->with('field_phone', '+1 612-803-0704');
    $this->alter($profile);
  }

  /**
   * A phone already given is kept.
   */
  public function testKeepsGivenPhone(): void {
    $profile = $this->profile(FALSE);
    $profile->expects($this->never())->method('set');
    $this->alter($profile);
  }

  /**
   * Builds a profile whose phone field is empty or not.
   */
  protected function profile(bool $empty): ProfileInterface {
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('isEmpty')->willReturn($empty);
    $profile = $this->createMock(ProfileInterface::class);
    $profile->method('hasField')->willReturn(TRUE);
    $profile->method('get')->willReturn($field);
    return $profile;
  }

  /**
   * Runs the alter with an Apple Pay charge, phone in billing details only.
   */
  protected function alter(ProfileInterface $profile): void {
    $config_factory = $this->getConfigFactoryStub([
      'commerce_stripe_enhanced.settings' => ['shipping_phone_field' => 'field_phone'],
    ]);
    $event = new ExpressCheckoutShippingProfileAlterEvent($profile, [
      'billing_details' => ['phone' => '6128030704'],
    ]);
    (new StripeExpressCheckoutSubscriber($config_factory))->onShippingProfileAlter($event);
  }

}
