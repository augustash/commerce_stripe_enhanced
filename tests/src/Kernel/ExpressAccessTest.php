<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_stripe_enhanced\Kernel;

use Drupal\commerce_order\Entity\Order;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Entity\OrderItem;
use Drupal\commerce_payment\Entity\PaymentGateway;
use Drupal\commerce_price\Price;
use Drupal\Tests\commerce_order\Kernel\OrderKernelTestBase;
use PHPUnit\Framework\Attributes\Group;

/**
 * A site refuses an order the express wallets.
 *
 * A wallet skips every checkout step between the cart and review, so a site
 * holding orders for approval had nothing standing between a held order and a
 * paid one. The refusal has to reach both places a wallet is decided: the
 * gateway every placement asks for, and the endpoint that creates the intent -
 * a hidden button stops nobody holding a stale page.
 *
 * The test module answers the hook from the order's data, so each case states
 * the answer it wants rather than standing up a rule to produce it.
 */
#[Group('aai')]
#[Group('commerce_stripe_enhanced')]
final class ExpressAccessTest extends OrderKernelTestBase {

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
   * The express methods helper.
   *
   * @var \Drupal\commerce_stripe_enhanced\ExpressMethods
   */
  protected $expressMethods;

  /**
   * A customer allowed to update any order, so only the express check decides.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $customer;

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
        'express_checkout' => ['enable_on_cart' => TRUE],
      ],
    ])->save();

    $this->expressMethods = $this->container->get('commerce_stripe_enhanced.express_methods');
    $this->customer = $this->createUser(['administer commerce_order']);
    $this->container->get('router.builder')->rebuild();
  }

  /**
   * A module with no opinion leaves the wallets where they were.
   */
  public function testNeutralKeepsWallets(): void {
    $order = $this->createOrder(NULL);

    $this->assertTrue($this->expressMethods->access($order)->isAllowed());
    $this->assertSame('stripe', $this->expressMethods->expressGateway($order)?->id());
    $this->assertTrue($this->expressMethods->isExpressOffered($order));
    $this->assertTrue($this->confirmAccess($order)->isAllowed());
  }

  /**
   * A refusal removes the gateway and closes the endpoint that charges.
   */
  public function testForbiddenRefusesWallets(): void {
    $order = $this->createOrder('forbidden');

    $this->assertTrue($this->expressMethods->access($order)->isForbidden());
    $this->assertNull($this->expressMethods->expressGateway($order));
    // The pane keeps its wallets only while no express element is offered, so
    // this is what gives a refused order its wallets back where the steps run.
    $this->assertFalse($this->expressMethods->isExpressOffered($order));
    $this->assertSame([], $this->expressMethods->enabledMethods($order));
    $this->assertTrue($this->confirmAccess($order)->isForbidden());
  }

  /**
   * Another module's allowance does not override a refusal.
   */
  public function testAllowedDoesNotOverrideForbidden(): void {
    $order = $this->createOrder('allowed');
    $this->assertTrue($this->confirmAccess($order)->isAllowed());

    $order->setData('express_access', 'forbidden')->save();
    $this->assertTrue($this->confirmAccess($order)->isForbidden());
  }

  /**
   * Creates an order the test module answers for.
   *
   * @param string|null $answer
   *   'forbidden', 'allowed', or NULL for no opinion.
   *
   * @return \Drupal\commerce_order\Entity\OrderInterface
   *   The order.
   */
  protected function createOrder(?string $answer): OrderInterface {
    $order_item = OrderItem::create([
      'type' => 'test',
      'quantity' => 1,
      'unit_price' => new Price('100.00', 'USD'),
    ]);
    $order_item->save();
    $order = Order::create([
      'type' => 'default',
      'state' => 'draft',
      'uid' => $this->customer->id(),
      'store_id' => $this->store->id(),
      'order_items' => [$order_item],
    ]);
    if ($answer) {
      $order->setData('express_access', $answer);
    }
    $order->save();

    return $order;
  }

  /**
   * Checks access to the express confirm endpoint, which creates the intent.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  protected function confirmAccess(OrderInterface $order) {
    return $this->container->get('access_manager')->checkNamedRoute(
      'commerce_stripe.express_checkout.payment_confirm',
      ['commerce_payment_gateway' => 'stripe', 'commerce_order' => $order->id()],
      $this->customer,
      TRUE,
    );
  }

}
