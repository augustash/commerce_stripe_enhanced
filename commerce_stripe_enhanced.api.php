<?php

/**
 * @file
 * Hooks provided by the Commerce Stripe Enhanced module.
 */

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\Core\Access\AccessResult;

/**
 * @addtogroup hooks
 * @{
 */

/**
 * Decides whether an order may be paid through an express wallet.
 *
 * A wallet skips every checkout step between the cart and review, so a site
 * with a rule enforced on those steps - an order that must be approved first,
 * a price staff quote by hand - refuses the wallet here. A refused order loses
 * the express buttons on the cart and in checkout, its express endpoints are
 * denied, and its wallets stay available inside the Payment Element instead.
 *
 * Only a forbidden result refuses; return neutral to have no opinion.
 *
 * @param \Drupal\commerce_order\Entity\OrderInterface $order
 *   The order.
 *
 * @return \Drupal\Core\Access\AccessResultInterface
 *   Forbidden to refuse the wallet.
 */
function hook_commerce_stripe_enhanced_express_access(OrderInterface $order) {
  return AccessResult::forbiddenIf($order->getData('needs_approval', FALSE));
}

/**
 * @} End of "addtogroup hooks".
 */
