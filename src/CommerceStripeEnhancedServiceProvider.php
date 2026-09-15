<?php

namespace Drupal\commerce_stripe_enhanced;

use Drupal\commerce_stripe_enhanced\EventSubscriber\OrderPaymentIntentSubscriber;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderBase;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Swaps in an order subscriber that keeps the payment breakdown.
 *
 * @see \Drupal\commerce_stripe_enhanced\EventSubscriber\OrderPaymentIntentSubscriber
 */
class CommerceStripeEnhancedServiceProvider extends ServiceProviderBase {

  /**
   * {@inheritdoc}
   */
  public function alter(ContainerBuilder $container) {
    if ($container->hasDefinition('commerce_stripe.order_events_subscriber')) {
      $container->getDefinition('commerce_stripe.order_events_subscriber')
        ->setClass(OrderPaymentIntentSubscriber::class)
        ->addArgument(new Reference('commerce_stripe_enhanced.payment_breakdown'));
    }
  }

}
