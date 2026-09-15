<?php

namespace Drupal\commerce_stripe_enhanced\Ajax;

use Drupal\Core\Ajax\CommandInterface;

/**
 * Tells the Payment Element whether its step saved, so it can confirm or stop.
 *
 * @see js/stripe-payment-element.js
 */
class StepSavedCommand implements CommandInterface {

  /**
   * Constructs a StepSavedCommand.
   *
   * @param bool $saved
   *   Whether the step's panes submitted without errors.
   */
  public function __construct(protected bool $saved) {}

  /**
   * {@inheritdoc}
   */
  public function render() {
    return [
      'command' => 'commerceStripeEnhancedStepSaved',
      'saved' => $this->saved,
    ];
  }

}
