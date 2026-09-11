<?php

namespace Drupal\commerce_stripe_enhanced\Plugin\Commerce\CheckoutFlow;

use Drupal\commerce_checkout\Plugin\Commerce\CheckoutFlow\MultistepDefault as MultistepDefaultBase;
use Drupal\commerce_stripe_enhanced\StripePaymentElementFlowTrait;
use Drupal\Core\Form\FormStateInterface;

/**
 * Commerce's stock multistep flow, with the Payment Element in the right place.
 *
 * Carries no plugin annotation: it replaces the class of the stock
 * multistep_default flow rather than offering a flow beside it, so a site that
 * never wrote a flow of its own gets this with no code and no reconfiguration.
 *
 * A site with its own flow plugin uses the trait directly instead.
 *
 * @see commerce_stripe_enhanced_commerce_checkout_flow_info_alter()
 * @see \Drupal\commerce_stripe_enhanced\StripePaymentElementFlowTrait
 */
class MultistepDefault extends MultistepDefaultBase {

  use StripePaymentElementFlowTrait;

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $step_id = NULL) {
    // Before the panes build: getVisiblePanes() resolves every pane's
    // isVisible() up front, and stripe_review keys its visibility on the
    // order's gateway.
    $this->trackSelectedPaymentGateway($form_state, $step_id);
    $form = parent::buildForm($form, $form_state, $step_id);
    $this->nestPaymentElement($form);
    return $form;
  }

}
