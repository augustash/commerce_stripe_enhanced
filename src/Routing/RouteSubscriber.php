<?php

namespace Drupal\commerce_stripe_enhanced\Routing;

use Drupal\commerce_stripe_enhanced\ExpressCheckoutContext;
use Drupal\Core\Routing\RouteSubscriberBase;
use Symfony\Component\Routing\RouteCollection;

/**
 * Puts the express access check on commerce_stripe's express endpoints.
 *
 * Matched by prefix rather than listed, so an endpoint upstream adds later is
 * covered the day it appears.
 *
 * @see \Drupal\commerce_stripe_enhanced\Access\ExpressAccessCheck
 */
class RouteSubscriber extends RouteSubscriberBase {

  /**
   * {@inheritdoc}
   */
  protected function alterRoutes(RouteCollection $collection) {
    foreach ($collection->all() as $name => $route) {
      if (str_starts_with($name, ExpressCheckoutContext::ROUTE_PREFIX)) {
        $route->setRequirement('_commerce_stripe_enhanced_express_access', 'TRUE');
      }
    }
  }

}
