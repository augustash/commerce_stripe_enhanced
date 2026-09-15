<?php

namespace Drupal\commerce_stripe_enhanced\EventSubscriber;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_stripe\Event\PaymentIntentCreateEvent;
use Drupal\commerce_stripe\Event\PaymentIntentUpdateEvent;
use Drupal\commerce_stripe_enhanced\ExpressCheckoutContext;
use Drupal\commerce_stripe_enhanced\PaymentBreakdown;
use Drupal\commerce_stripe_enhanced\Plugin\Commerce\PaymentGateway\StripePaymentElement;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Puts the order's breakdown on its Stripe payment.
 *
 * On the intent as it is created: amount_details, the order it belongs to, and
 * the shipping method as the carrier - the shipping address upstream already
 * sends. On the payment once recorded: the breakdown as metadata, the part the
 * dashboard shows.
 *
 * @see \Drupal\commerce_stripe_enhanced\PaymentBreakdown
 * @see \Drupal\commerce_stripe_enhanced\EventSubscriber\OrderPaymentIntentSubscriber
 *   which keeps amount_details in step as the order's amount changes.
 */
class PaymentBreakdownSubscriber implements EventSubscriberInterface {

  /**
   * Constructs the subscriber.
   *
   * @param \Drupal\commerce_stripe_enhanced\PaymentBreakdown $breakdown
   *   The payment breakdown builder.
   * @param \Drupal\commerce_stripe_enhanced\ExpressCheckoutContext $expressContext
   *   The express checkout context.
   */
  public function __construct(
    protected PaymentBreakdown $breakdown,
    protected ExpressCheckoutContext $expressContext,
  ) {}

  /**
   * {@inheritdoc}
   *
   * Spelled out rather than using StripeEvents constants, for the reason given
   * on StripePaymentIntentSubscriber::getSubscribedEvents().
   */
  public static function getSubscribedEvents(): array {
    return [
      'commerce_stripe.payment_intent.create' => 'onIntentCreate',
      'commerce_stripe.payment_intent.update' => 'onIntentUpdate',
    ];
  }

  /**
   * Adds the breakdown, order reference and carrier to a new intent.
   *
   * @param \Drupal\commerce_stripe\Event\PaymentIntentCreateEvent $event
   *   The intent create event.
   */
  public function onIntentCreate(PaymentIntentCreateEvent $event): void {
    $order = $event->getOrder();
    if (!$this->applies($order)) {
      return;
    }
    $attributes = $event->getIntentAttributes();

    // The order number is only assigned when the order is placed, after both
    // the intent and the payment exist, so the id is the reference there is.
    $attributes['description'] ??= sprintf('Order %s', $order->id());
    $attributes['payment_details']['order_reference'] = (string) $order->id();

    if ($details = $this->breakdown->amountDetails($order, (int) $attributes['amount'])) {
      $attributes['amount_details'] = $details;
    }
    // Upstream sends no shipping at all on an express intent, and Stripe will
    // not take a carrier without the address it belongs to.
    if (!empty($attributes['shipping']) && $carrier = $this->breakdown->carrier($order)) {
      $attributes['shipping']['carrier'] = $carrier;
    }

    $event->setIntentAttributes($attributes);
  }

  /**
   * Adds the breakdown as metadata once the payment is recorded.
   *
   * @param \Drupal\commerce_stripe\Event\PaymentIntentUpdateEvent $event
   *   The intent update event.
   */
  public function onIntentUpdate(PaymentIntentUpdateEvent $event): void {
    $order = $event->getOrder();
    if ($this->applies($order)) {
      $event->addMetadata($this->breakdown->metadata($order));
    }
  }

  /**
   * Whether the order pays through this module's gateway.
   *
   * An express confirm creates its intent before recording the gateway on the
   * order, so there the gateway comes from the request.
   */
  protected function applies(OrderInterface $order): bool {
    $gateway = $order->get('payment_gateway')->entity ?? $this->expressContext->gateway();
    return $gateway && $gateway->getPlugin() instanceof StripePaymentElement;
  }

}
