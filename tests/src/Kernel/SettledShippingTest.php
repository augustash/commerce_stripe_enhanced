<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_stripe_enhanced\Kernel;

use Drupal\commerce_order\Entity\Order;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Entity\OrderItem;
use Drupal\commerce_payment\Entity\PaymentGateway;
use Drupal\commerce_price\Price;
use Drupal\commerce_stripe_enhanced\EventSubscriber\SettledShippingSubscriber;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\profile\Entity\Profile;
use Drupal\Tests\commerce_order\Kernel\OrderKernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * A wallet leaves an order with settled shipping as it found it.
 *
 * commerce_stripe's express confirm clears the order's checkout data unless
 * the order is already flagged as an express checkout, and its cancel deletes
 * the shipments outright. On an order whose shipping a site fixed - a freight
 * quote staff entered - either one discards it. The confirm here must find the
 * order flagged, and the cancel must be answered without upstream's.
 *
 * The subscriber is driven directly with the route each endpoint matches.
 * Running the endpoints themselves would create intents at Stripe, and what is
 * ours is which controller runs and what the order holds afterwards.
 */
#[Group('aai')]
#[Group('commerce_stripe_enhanced')]
final class SettledShippingTest extends OrderKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'commerce_checkout',
    'commerce_payment',
    'commerce_stripe',
    'commerce_stripe_enhanced',
    'commerce_stripe_enhanced_test',
  ];

  /**
   * The controller upstream would run, which must not when it is replaced.
   *
   * @var callable
   */
  protected $upstream;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    PaymentGateway::create([
      'id' => 'stripe',
      'label' => 'Stripe',
      'plugin' => 'stripe_payment_element',
      'configuration' => [
        'authentication_method' => 'api_keys',
        'publishable_key' => 'pk_test_stub',
        'secret_key' => 'sk_test_stub',
        'mode' => 'test',
      ],
    ])->save();

    $this->upstream = fn () => $this->fail('Upstream controller ran.');
  }

  /**
   * A settled order reaches the confirm already flagged, so nothing is cleared.
   */
  public function testConfirmKeepsSettledOrder(): void {
    $order = $this->createOrder(TRUE);

    $event = $this->dispatch('commerce_stripe.express_checkout.payment_confirm', $order);

    $this->assertTrue($order->getData('stripe_express_checkout'));
    // The confirm itself still runs: it creates the intent being paid.
    $this->assertSame($this->upstream, $event->getController());
  }

  /**
   * A dismissed sheet drops the attempt and leaves the order.
   */
  public function testCancelKeepsSettledOrder(): void {
    $order = $this->createOrder(TRUE);
    $order->setData('stripe_express_checkout', TRUE);
    $order->set('checkout_step', 'review');
    $order->set('payment_gateway', 'stripe');
    $billing_profile = Profile::create(['type' => 'customer', 'uid' => 0]);
    $billing_profile->save();
    $order->setBillingProfile($billing_profile);
    $order->save();

    $event = $this->dispatch('commerce_stripe.express_checkout.cancel', $order);
    $this->assertNotSame($this->upstream, $event->getController());
    $response = ($event->getController())();

    $this->assertSame(['amount' => 10000], json_decode($response->getContent(), TRUE));
    $order = Order::load($order->id());
    $this->assertNull($order->getData('stripe_express_checkout'), 'A later card payment is not processed as an express one.');
    $this->assertNull($order->get('checkout_step')->value);
    // Upstream deletes the billing profile and the shipments; both stay.
    $this->assertSame($billing_profile->id(), $order->getBillingProfile()?->id());
    $this->assertCount(1, $order->getItems());
  }

  /**
   * An order whose shipping is open goes through upstream's endpoints.
   */
  public function testOpenOrderUntouched(): void {
    $order = $this->createOrder(FALSE);

    foreach (['payment_confirm', 'cancel'] as $endpoint) {
      $event = $this->dispatch('commerce_stripe.express_checkout.' . $endpoint, $order);
      $this->assertSame($this->upstream, $event->getController());
    }
    $this->assertNull($order->getData('stripe_express_checkout'));
  }

  /**
   * Dispatches the controller event for an express endpoint.
   */
  protected function dispatch(string $route_name, OrderInterface $order): ControllerEvent {
    $route_match = $this->createMock(RouteMatchInterface::class);
    $route_match->method('getRouteName')->willReturn($route_name);
    $route_match->method('getParameter')->with('commerce_order')->willReturn($order);

    $event = new ControllerEvent(
      $this->createMock(HttpKernelInterface::class),
      $this->upstream,
      new Request(),
      HttpKernelInterface::MAIN_REQUEST,
    );
    (new SettledShippingSubscriber(
      $route_match,
      $this->container->get('commerce_stripe_enhanced.express_methods'),
      $this->container->get('commerce_price.minor_units_converter'),
    ))->onController($event);

    return $event;
  }

  /**
   * Creates an order whose shipping is settled or open.
   */
  protected function createOrder(bool $settled): OrderInterface {
    $order_item = OrderItem::create([
      'type' => 'test',
      'quantity' => 1,
      'unit_price' => new Price('100.00', 'USD'),
    ]);
    $order_item->save();
    $order = Order::create([
      'type' => 'default',
      'state' => 'draft',
      'store_id' => $this->store->id(),
      'order_items' => [$order_item],
    ]);
    $order->setData('express_settled', $settled);
    $order->save();

    return $order;
  }

}
