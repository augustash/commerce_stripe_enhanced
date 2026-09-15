<?php

namespace Drupal\commerce_stripe_enhanced;

use Drupal\commerce_payment\Entity\PaymentGatewayInterface;
use Drupal\Core\Routing\RouteMatchInterface;

/**
 * Tells whether the current request is part of an express checkout.
 *
 * A wallet collects a delivery choice through a sheet rather than a form, and a
 * sheet can only show a flat list of rates with a name, a price and one line of
 * text. Anything a site normally asks *about* a delivery method - an access
 * surcharge, whether a certificate of insurance is needed - has nowhere to live
 * there unless it is expressed as more rates in that list.
 *
 * Expanding rates that way is right for a wallet and wrong everywhere else: the
 * site's own checkout asks those questions properly, with checkboxes under the
 * method, and turning them into extra radios there would replace a considered
 * form with a longer list.
 *
 * So the decision needs to know where it is being made, which is what this
 * answers. Read from the route rather than a flag set earlier in the request:
 * the only rate calculations a wallet drives are the ones behind
 * commerce_stripe's own express endpoints, and a route is a fact rather than
 * state that has to be kept in step.
 */
class ExpressCheckoutContext {

  /**
   * The prefix every express checkout route shares.
   */
  const ROUTE_PREFIX = 'commerce_stripe.express_checkout.';

  /**
   * Constructs the context.
   *
   * @param \Drupal\Core\Routing\RouteMatchInterface $routeMatch
   *   The current route match.
   */
  public function __construct(protected RouteMatchInterface $routeMatch) {}

  /**
   * Whether a wallet is driving this request.
   *
   * @return bool
   *   TRUE on the express checkout endpoints - the shipping address and rate
   *   changes a wallet sheet makes, and the confirm it ends with.
   */
  public function isExpressRequest(): bool {
    $route_name = $this->routeMatch->getRouteName();

    return $route_name !== NULL && str_starts_with($route_name, self::ROUTE_PREFIX);
  }

  /**
   * The gateway a wallet is paying through, on the confirm that names it.
   *
   * commerce_stripe's express confirm creates the intent before it records
   * the gateway on the order, so anything reading the order's gateway while
   * the intent is built finds none. The route has it.
   *
   * @return \Drupal\commerce_payment\Entity\PaymentGatewayInterface|null
   *   The gateway, or NULL outside an express request that carries one.
   */
  public function gateway(): ?PaymentGatewayInterface {
    if (!$this->isExpressRequest()) {
      return NULL;
    }
    $gateway = $this->routeMatch->getParameter('commerce_payment_gateway');
    return $gateway instanceof PaymentGatewayInterface ? $gateway : NULL;
  }

}
