<?php

namespace Drupal\commerce_stripe_enhanced\EventSubscriber;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_price\MinorUnitsConverterInterface;
use Drupal\commerce_stripe_enhanced\ExpressMethods;
use Drupal\Core\Routing\RouteMatchInterface;
use Stripe\Exception\ApiErrorException;
use Stripe\PaymentIntent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Keeps a wallet from clearing an order whose shipping is settled.
 *
 * commerce_stripe's express endpoints assume the wallet owns shipping. The
 * confirm clears the order's checkout data - billing, shipments, step - unless
 * the order is already flagged as an express checkout, and a dismissed sheet
 * deletes the shipments outright. On a settled order either one discards the
 * shipping a site fixed, so:
 *
 * - The confirm finds the order already flagged, and leaves it as it is.
 * - The cancel is answered here instead: the intent the confirm created is
 *   cancelled and the express flag dropped, so a later payment through the
 *   Payment Element is not processed as an express one. Shipments, profiles
 *   and the order's items stay as they are.
 *
 * @see \Drupal\commerce_stripe_enhanced\ExpressMethods::isShippingSettled()
 */
class SettledShippingSubscriber implements EventSubscriberInterface {

  /**
   * Constructs the subscriber.
   *
   * @param \Drupal\Core\Routing\RouteMatchInterface $routeMatch
   *   The current route match.
   * @param \Drupal\commerce_stripe_enhanced\ExpressMethods $expressMethods
   *   The express methods helper.
   * @param \Drupal\commerce_price\MinorUnitsConverterInterface $minorUnitsConverter
   *   The minor units converter.
   */
  public function __construct(
    protected RouteMatchInterface $routeMatch,
    protected ExpressMethods $expressMethods,
    protected MinorUnitsConverterInterface $minorUnitsConverter,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      KernelEvents::CONTROLLER => 'onController',
    ];
  }

  /**
   * Steps in ahead of the express confirm and cancel on a settled order.
   *
   * @param \Symfony\Component\HttpKernel\Event\ControllerEvent $event
   *   The controller event.
   */
  public function onController(ControllerEvent $event): void {
    $route_name = $this->routeMatch->getRouteName();
    if (!in_array($route_name, ['commerce_stripe.express_checkout.payment_confirm', 'commerce_stripe.express_checkout.cancel'], TRUE)) {
      return;
    }
    $order = $this->routeMatch->getParameter('commerce_order');
    if (!$order instanceof OrderInterface || !$this->expressMethods->isShippingSettled($order)) {
      return;
    }

    if ($route_name === 'commerce_stripe.express_checkout.payment_confirm') {
      // The same object the controller is handed, which saves it.
      $order->setData('stripe_express_checkout', TRUE);
      return;
    }

    $event->setController(fn () => $this->cancel($order));
  }

  /**
   * Abandons a wallet payment without touching the order's shipping.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The amount the element should show, as upstream's cancel returns it.
   */
  protected function cancel(OrderInterface $order): JsonResponse {
    $intent_id = $order->getData('stripe_intent');
    $payment_gateway = $order->get('payment_gateway')->entity;
    if ($intent_id && $payment_gateway) {
      // Initialises the Stripe client with the gateway's keys.
      $payment_gateway->getPlugin();
      try {
        PaymentIntent::retrieve($intent_id)->cancel();
      }
      catch (ApiErrorException) {
        // Already cancelled or never confirmable; either way it is done with.
      }
      $order->unsetData('stripe_intent');
    }
    $order->unsetData('stripe_express_checkout');
    // The confirm moved the order to review; checkout resolves it afresh, as
    // upstream's cancel leaves it.
    $order->set('checkout_step', NULL);
    $order->save();

    return new JsonResponse([
      'amount' => $this->minorUnitsConverter->toMinorUnits($order->getTotalPrice()),
    ]);
  }

}
