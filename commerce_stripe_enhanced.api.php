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
 * Declares an order's shipping settled, so a wallet takes payment only.
 *
 * Upstream's express flow owns shipping: the sheet asks for an address and
 * re-rates it, and the confirm and a dismissed sheet both clear what checkout
 * collected. For an order whose shipping the site has already fixed - a quote,
 * an approved delivery - return TRUE: the sheet then asks for no address, the
 * order's shipping and profiles are left alone, and the wallet charges the
 * order's total as it stands.
 *
 * @param \Drupal\commerce_order\Entity\OrderInterface $order
 *   The order.
 *
 * @return bool
 *   TRUE if the order's shipping is settled.
 */
function hook_commerce_stripe_enhanced_express_shipping_settled(OrderInterface $order) {
  return (bool) $order->getData('shipping_quoted', FALSE);
}

/**
 * @} End of "addtogroup hooks".
 */
