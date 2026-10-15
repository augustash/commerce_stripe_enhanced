<?php

namespace Drupal\commerce_stripe_enhanced\Access;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_stripe_enhanced\ExpressMethods;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Routing\RouteMatchInterface;

/**
 * Refuses commerce_stripe's express endpoints to an order refused a wallet.
 *
 * Hiding the buttons is not the gate. The confirm endpoint is what creates the
 * payment intent and moves the order to review, and it answers anyone holding
 * the order's update access - a stale tab, a cached page, a request made by
 * hand. Refusing it here means no intent exists to confirm, so no money moves.
 *
 * The other endpoints are refused with it. Cancel clears the order's checkout
 * data, which on an order a site holds for review would discard what staff
 * entered on it.
 *
 * @see \Drupal\commerce_stripe_enhanced\ExpressMethods::access()
 */
class ExpressAccessCheck implements AccessInterface {

  /**
   * Constructs the access check.
   *
   * @param \Drupal\commerce_stripe_enhanced\ExpressMethods $expressMethods
   *   The express methods helper.
   */
  public function __construct(protected ExpressMethods $expressMethods) {}

  /**
   * Checks the order named by the route may use a wallet.
   *
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The route match.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  public function access(RouteMatchInterface $route_match): AccessResultInterface {
    $order = $route_match->getParameter('commerce_order');
    if (!$order instanceof OrderInterface) {
      return AccessResult::neutral();
    }

    return $this->expressMethods->access($order)->isForbidden()
      ? AccessResult::forbidden('This order may not be paid through an express wallet.')
      : AccessResult::allowed();
  }

}
