<?php

namespace Drupal\commerce_stripe_enhanced\EventSubscriber;

use Drupal\commerce_order\Event\OrderEvent;
use Drupal\commerce_order\OrderAssignmentInterface;
use Drupal\commerce_price\MinorUnitsConverterInterface;
use Drupal\commerce_stripe\ErrorHelper;
use Drupal\commerce_stripe\EventSubscriber\OrderPaymentIntentSubscriber as OrderPaymentIntentSubscriberBase;
use Drupal\commerce_stripe_enhanced\PaymentBreakdown;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Password\PasswordGeneratorInterface;
use Psr\Log\LoggerInterface;
use Stripe\Exception\ApiErrorException;
use Stripe\PaymentIntent;

/**
 * Keeps an intent's amount_details in step with its amount.
 *
 * Upstream updates the intent's amount whenever the order balance changes,
 * sending the amount alone. Once an intent carries amount_details Stripe
 * refuses that - "the total value of the existing amount_details does not
 * match the updated amount" - with or without enforce_arithmetic_validation,
 * and the intent keeps charging the old total. So every amount change here
 * restates the breakdown, or clears it when the order no longer adds up.
 *
 * Swapped in for commerce_stripe.order_events_subscriber by
 * CommerceStripeEnhancedServiceProvider.
 */
class OrderPaymentIntentSubscriber extends OrderPaymentIntentSubscriberBase {

  /**
   * The orders behind the intents awaiting an amount update.
   *
   * @var \Drupal\commerce_order\Entity\OrderInterface[]
   */
  protected array $orders = [];

  /**
   * Constructs the subscriber.
   *
   * @param \Drupal\commerce_price\MinorUnitsConverterInterface $minorUnitsConverter
   *   The minor units converter.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\commerce_order\OrderAssignmentInterface $orderAssignment
   *   The order assignment.
   * @param \Drupal\Core\Password\PasswordGeneratorInterface $passwordGenerator
   *   The password generator.
   * @param \Drupal\commerce_stripe_enhanced\PaymentBreakdown $breakdown
   *   The payment breakdown builder.
   */
  public function __construct(
    MinorUnitsConverterInterface $minorUnitsConverter,
    LoggerInterface $logger,
    EntityTypeManagerInterface $entityTypeManager,
    OrderAssignmentInterface $orderAssignment,
    PasswordGeneratorInterface $passwordGenerator,
    protected PaymentBreakdown $breakdown,
  ) {
    parent::__construct($minorUnitsConverter, $logger, $entityTypeManager, $orderAssignment, $passwordGenerator);
  }

  /**
   * {@inheritdoc}
   *
   * Also cancels an intent the order stops referencing, however it came to.
   * Upstream cancels only when the payment method changes on a Stripe gateway,
   * and returns before looking once the order has moved to another gateway -
   * so choosing PayPal or Affirm after the card's intent was minted left that
   * intent open at Stripe for good. The status check upstream applies here
   * too, so a payment that has gone through is never cancelled.
   */
  public function onOrderPreSave(OrderEvent $event): void {
    parent::onOrderPreSave($event);

    $order = $event->getOrder();
    $original = $order->original ?? NULL;
    $dropped = $original?->getData('stripe_intent');
    if (!$dropped || $dropped === $order->getData('stripe_intent') || isset($this->cancelList[$dropped])) {
      return;
    }
    $intent = $this->getIntent($dropped);
    if ($intent instanceof PaymentIntent && !in_array($intent->status, [
      PaymentIntent::STATUS_SUCCEEDED,
      PaymentIntent::STATUS_PROCESSING,
      PaymentIntent::STATUS_REQUIRES_CAPTURE,
      PaymentIntent::STATUS_CANCELED,
    ], TRUE)) {
      $this->cancelList[$dropped] = $dropped;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function onOrderUpdate(OrderEvent $event): void {
    parent::onOrderUpdate($event);
    $order = $event->getOrder();
    $intent_id = $order->getData('stripe_intent');
    if ($intent_id !== NULL && isset($this->updateList[$intent_id])) {
      $this->orders[$intent_id] = $order;
    }
  }

  /**
   * {@inheritdoc}
   *
   * The update loop is the parent's, with the breakdown added to the call. The
   * parent then runs with nothing left to update, for its cancellations.
   */
  public function destruct(): void {
    foreach ($this->updateList as $intent_id => $balance) {
      // Being cancelled below; nothing to bring up to date.
      if (isset($this->cancelList[$intent_id])) {
        continue;
      }
      try {
        $intent = $this->getIntent($intent_id);
        if (!($intent instanceof PaymentIntent) || !in_array($intent->status, [
          PaymentIntent::STATUS_REQUIRES_PAYMENT_METHOD,
          PaymentIntent::STATUS_REQUIRES_CONFIRMATION,
        ], TRUE)) {
          continue;
        }
        $params = [
          'amount' => $balance['amount'],
          'currency' => $balance['currency'],
        ];
        // Whatever gateway the order is on now: an intent minted with a
        // breakdown refuses any amount that does not restate it.
        $order = $this->orders[$intent_id] ?? NULL;
        if ($order && $order->getBalance()) {
          // The amount and its breakdown are read off the same order at the
          // same moment. The balance was recorded at whichever save changed
          // it, and a later save in the request can change the order again.
          $params['amount'] = $this->minorUnitsConverter->toMinorUnits($order->getBalance());
          $params['currency'] = $order->getBalance()->getCurrencyCode();
          // An empty string clears a breakdown that no longer adds up, and is
          // accepted on an intent that never had one.
          $params['amount_details'] = $this->breakdown->amountDetails($order, $params['amount']) ?? '';
        }
        PaymentIntent::update($intent_id, $params);
      }
      catch (ApiErrorException $e) {
        ErrorHelper::handleException($e);
      }
    }
    $this->updateList = [];
    $this->orders = [];
    parent::destruct();
  }

}
