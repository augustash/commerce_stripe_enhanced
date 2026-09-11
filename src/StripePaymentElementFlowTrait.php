<?php

namespace Drupal\commerce_stripe_enhanced;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Form\FormStateInterface;

/**
 * Puts the Stripe Payment Element with the radio that asks for it.
 *
 * A checkout flow plugin uses this trait and calls the two methods around its
 * parent::buildForm() - the tracking before, the nesting after:
 *
 * @code
 * public function buildForm(array $form, FormStateInterface $form_state, $step_id = NULL) {
 *   $this->trackSelectedPaymentGateway($form_state, $step_id);
 *   $form = parent::buildForm($form, $form_state, $step_id);
 *   $this->nestPaymentElement($form);
 *   return $form;
 * }
 * @endcode
 *
 * A site with no custom flow gets this for free: the module points the stock
 * multistep_default flow at a subclass that does exactly the above.
 *
 * Both methods are no-ops unless the payment pane and the stripe_review pane
 * are on the step being built, so a flow can use the trait unconditionally.
 *
 * @see \Drupal\commerce_stripe_enhanced\Plugin\Commerce\CheckoutFlow\MultistepDefault
 */
trait StripePaymentElementFlowTrait {

  /**
   * Names the payment information pane on this site.
   *
   * Commerce's stock id, unless a site defined its own pane in its place - in
   * which case it names that one in
   * commerce_stripe_enhanced.settings:payment_pane_id.
   *
   * @return string
   *   The pane id.
   */
  protected function paymentPaneId(): string {
    return \Drupal::config('commerce_stripe_enhanced.settings')
      ->get('payment_pane_id') ?: 'payment_information';
  }

  /**
   * Copies the selected payment option's gateway onto the order.
   *
   * Commerce only writes payment_gateway when the payment pane is submitted,
   * which is one step too late: the Stripe Payment Element is rendered by the
   * stripe_review pane, and that pane hides itself when the order has no
   * gateway. So on a first visit to the payment step the card fields could
   * never appear, and after a radio change they would still describe the
   * previously selected method.
   *
   * This has to happen before the panes build, not inside one, because
   * getVisiblePanes() resolves every pane's isVisible() up front - by the time
   * the payment pane builds, stripe_review has already been excluded.
   *
   * A gateway is left unsaved on purpose. Creating the intent saves the order
   * anyway (StripePaymentElement::createPaymentIntent()), and submitting the
   * pane writes it for real, so there is nothing to gain from a write on a
   * request the customer may simply abandon. A changed payment method is the
   * exception, and has to be written here - see below.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current form state.
   * @param string|null $step_id
   *   The step being built.
   */
  protected function trackSelectedPaymentGateway(FormStateInterface $form_state, $step_id = NULL) {
    $payment_pane = $this->getPane($this->paymentPaneId());
    if (!$payment_pane || $payment_pane->getStepId() !== $step_id || !$payment_pane->isVisible()) {
      return;
    }

    $order = $this->getOrder();
    $gateway_storage = $this->entityTypeManager->getStorage('commerce_payment_gateway');
    $gateways = $gateway_storage->loadMultipleForOrder($order);
    if (!$gateways) {
      return;
    }

    /** @var \Drupal\commerce_payment\PaymentOptionsBuilderInterface $options_builder */
    $options_builder = \Drupal::service('commerce_payment.options_builder');
    $options = $options_builder->buildOptions($order, $gateways);
    if (!$options) {
      return;
    }

    // Mirror how the payment pane picks its default, so the order always
    // agrees with the radio the customer is actually looking at.
    $input = $form_state->getUserInput() ?? [];
    $selected_id = NestedArray::getValue($input, [
      $payment_pane->getPluginId(),
      'payment_method',
    ]);
    $option = $options[$selected_id] ?? NULL;

    // Nothing is preselected, so until the customer picks there is no gateway
    // to record - and no intent to create. Asking Stripe for one on every visit
    // to the step spends an API call on people who have not chosen yet, and
    // leaves an abandoned intent behind whenever they never do. A stored method
    // is the exception: that is a choice already made.
    if (!$option) {
      // Drop a gateway recorded on an earlier visit before asking which option
      // is the default, not after. Commerce reads that gateway when it picks
      // one - and tests the gateway *config entity* against
      // SupportsStoredPaymentMethodsInterface, which no config entity
      // implements, so a gateway with no payment method beside it always
      // resolves to that gateway's own "pay with a new method" option. Left in
      // place it therefore answers a question the customer has not been asked,
      // and answers it differently from the pane: the pane builds after this
      // runs, by which point the gateway is gone, so it falls through to the
      // customer's default card and ticks it. One page, two answers - a saved
      // card selected above a step with no Payment Element on it.
      $order->set('payment_gateway', NULL);
      $default = $options_builder->selectDefaultOption($order, $options);
      if (!$default->getPaymentMethodId()) {
        // Nothing to record, and the gateway is already gone for this build.
        // stripe_review keys its visibility on it, so the step renders with no
        // card fields under a list where nothing is ticked. Only in memory:
        // nothing saves it back, and the next choice records its own.
        return;
      }
      $option = $default;
    }

    $gateway = $gateways[$option->getPaymentGatewayId()] ?? NULL;
    if (!$gateway) {
      return;
    }

    // Each gateway instance offers a different Stripe method, so an intent
    // minted for one cannot confirm the other. commerce_stripe only discards a
    // stored intent when the payment_method changes, and switching between two
    // new methods leaves that empty either side - so the card's intent survived
    // into Affirm, which then rendered against a list it was not on.
    $current = $order->get('payment_gateway')->target_id;
    if ($current && $current !== $gateway->id()) {
      $order->unsetData('stripe_intent');
    }

    $payment_method = NULL;
    if ($option->getPaymentMethodId()) {
      $payment_method = $this->entityTypeManager
        ->getStorage('commerce_payment_method')
        ->load($option->getPaymentMethodId());
    }

    $previous_method = $order->get('payment_method')->target_id;
    $order->set('payment_gateway', $gateway);
    $order->set('payment_method', $payment_method);

    // Persist a changed choice now rather than leaving the intent's own save to
    // carry it, for two reasons.
    //
    // The method, because commerce_stripe cancels the stored intent whenever
    // payment_method differs from the order's saved value on presave - and
    // while the selection is only held in memory, the save that stores a
    // freshly minted intent is exactly that write. The intent is cancelled by
    // the request that created it, so the step renders a client secret already
    // dead and confirm fails with payment_intent_unexpected_state. Its one
    // escape hatch spares an intent that is already succeeded or processing,
    // which a new one never is. Saving first leaves the comparison equal, so
    // only a genuine change of method discards an intent.
    //
    // The gateway, because an AJAX rebuild reloads the order from storage part
    // way through - commerce does it to pick up anything an ajax submit wrote -
    // and everything built after that reload reads the saved value. Held only
    // in memory, the choice is thrown away by that reload, so the card fields
    // arrived titled after the method chosen before this one. The discarded
    // intent above rides on the same save for the same reason.
    $changed = (string) ($payment_method?->id() ?? '') !== (string) ($previous_method ?? '')
      || $gateway->id() !== $current;
    if ($changed) {
      $order->save();
    }
  }

  /**
   * Moves the Stripe Payment Element inside the payment pane.
   *
   * The card fields belong with the radio that asks for them, but the two are
   * separate panes and so render as siblings. That is not only a layout
   * problem: the payment radios refresh over AJAX through
   * PaymentInformation::ajaxRefresh(), which replaces the payment pane and
   * nothing else, so a stale Payment Element was left on the page after
   * switching to PayPal or Affirm.
   *
   * Nesting it makes contrib's own callback cover both, rather than adding a
   * second AJAX mechanism alongside it.
   *
   * Safe to relocate only because this pane contributes no form input - it is
   * markup, an empty div for Stripe to mount into, and #attached settings. A
   * pane carrying real inputs would lose its values this way.
   *
   * @param array $form
   *   The built checkout form.
   */
  protected function nestPaymentElement(array &$form) {
    $pane_id = $this->paymentPaneId();
    if (!isset($form['stripe_review'], $form[$pane_id])) {
      return;
    }

    $element = $form['stripe_review'];
    unset($form['stripe_review']);

    // Sit directly under the radios, above the billing-address checkbox.
    $element['#weight'] = -5;

    // A saved card mounts nothing - Stripe confirms it against the client
    // secret alone - so a heading there would title an empty box. The element
    // still has to reach the page for the confirm handler to find it.
    $settings = $element['#attached']['drupalSettings']['commerceStripePaymentElement'] ?? [];
    if (empty($settings['showPaymentForm'])) {
      $form[$pane_id]['stripe_review'] = $element;
      unset($form[$pane_id]['#sorted']);
      return;
    }

    // Name the fields after the option they belong to. Unlabelled, a bare card
    // form sitting under a list of radios reads as a second, competing question
    // rather than the detail of the one already answered. The label comes from
    // the same gateway setting that titles the radio, so the two cannot drift.
    $gateway = $this->getOrder()->get('payment_gateway')->entity;
    $config = $gateway ? $gateway->getPlugin()->getConfiguration() : [];
    $method = $config['checkout_form_display_label']['custom_label'] ?? NULL;

    $form[$pane_id]['stripe_review'] = [
      '#type' => 'fieldset',
      '#title' => $method
        ? $this->t('@method Details', ['@method' => $method])
        : $this->t('Payment Details'),
      '#attributes' => ['class' => ['payment-method-details']],
      '#weight' => -5,
      'pane' => $element,
    ];
    // The pane was built before this child existed, so let it sort again.
    unset($form[$pane_id]['#sorted']);
    $this->markPaymentMethodChildren($form);
  }

  /**
   * Flags the parts of the payment pane that belong to the chosen method.
   *
   * The billing address is collected for the card, not for the order, so it
   * belongs with the card fields rather than beside the list of methods. Left
   * level with the radios it reads as a further question of its own.
   *
   * @param array $form
   *   The built checkout form.
   */
  protected function markPaymentMethodChildren(array &$form) {
    $pane_id = $this->paymentPaneId();
    foreach (['add_payment_method', 'billing_information'] as $key) {
      if (isset($form[$pane_id][$key])) {
        $form[$pane_id][$key]['#attributes']['class'][] = 'payment-method-child';
      }
    }
  }

}
