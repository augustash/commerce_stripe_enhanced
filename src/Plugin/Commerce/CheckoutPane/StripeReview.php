<?php

namespace Drupal\commerce_stripe_enhanced\Plugin\Commerce\CheckoutPane;

use Drupal\commerce_stripe\Plugin\Commerce\CheckoutPane\StripeReview as StripeReviewBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;

/**
 * Returns from Stripe to the step the payment form is actually on.
 *
 * The parent hardcodes 'review' as the step in the return URL, which is safe
 * only while the pane sits on a step of that name. We collect the card on the
 * payment step, so the order is still on 'payment' when Stripe sends the
 * customer back - and PaymentCheckoutController::validateStepId() compares the
 * requested step against the order's own before doing anything else. A
 * mismatch redirects, so onReturn() never runs: no payment method, no payment
 * entity, no placed order, while Stripe has already taken the money.
 *
 * @see \Drupal\commerce_payment\Controller\PaymentCheckoutController::validateStepId()
 */
class StripeReview extends StripeReviewBase {

  /**
   * {@inheritdoc}
   */
  public function buildPaneForm(array $pane_form, FormStateInterface $form_state, array &$complete_form): array {
    $pane_form = parent::buildPaneForm($pane_form, $form_state, $complete_form);

    $settings = &$pane_form['#attached']['drupalSettings'];

    // A card-riding wallet - Apple Pay, Google Pay - is not a payment method
    // type, so narrowing the intent to card does not remove it and it shows
    // here as a tab regardless. The express element offers it once, on its own
    // terms; re-offering it here asks a question already answered.
    //
    // Which wallets those are is derived, not listed, and only the ones this
    // order's express element actually offers are turned off: a site running no
    // express element keeps them here, where they are then the only way a
    // customer reaches a wallet at all.
    //
    // \Drupal:: rather than injection on purpose. Overriding the constructor
    // would pin this class to upstream's argument list, which has changed
    // before and takes the whole checkout down with it when it does.
    $express_methods = \Drupal::service('commerce_stripe_enhanced.express_methods');
    $wallets = $express_methods->cardRiding($express_methods->enabledMethods($this->order));
    if ($wallets && isset($settings['commerceStripePaymentElement']['paymentElementOptions'])) {
      $settings['commerceStripePaymentElement']['paymentElementOptions']['wallets'] = array_fill_keys($wallets, 'never');
    }

    // Stripe's consent notice for a card saved for later. A site may state its
    // own terms instead; consent is still needed, so this only hides Stripe's.
    if (\Drupal::config('commerce_stripe_enhanced.settings')->get('hide_card_terms')
      && isset($settings['commerceStripePaymentElement']['paymentElementOptions'])) {
      $settings['commerceStripePaymentElement']['paymentElementOptions']['terms']['card'] = 'never';
    }

    // Upstream hands the order's email to the element as a default value,
    // which fills a field the customer is shown - and a card form shows no
    // email field, so nothing carried it to Stripe: a guest's payment reached
    // the dashboard with no way to tell who paid. Declaring the field "never"
    // is Stripe's own way of saying the site supplies it at confirm, which
    // stripe-payment-element.js then does.
    if ($this->order->getEmail() && isset($settings['commerceStripePaymentElement']['paymentElementOptions'])) {
      $settings['commerceStripePaymentElement']['paymentElementOptions']['fields']['billingDetails']['email'] = 'never';
      $settings['commerceStripePaymentElement']['billingEmail'] = $this->order->getEmail();
    }

    // The Payment Element and the older Card Element each carry their own copy.
    foreach (['commerceStripePaymentElement', 'commerceStripe'] as $key) {
      if (!isset($settings[$key]['returnUrl'])) {
        continue;
      }
      $settings[$key]['returnUrl'] = Url::fromRoute('commerce_payment.checkout.return', [
        'commerce_order' => $this->order->id(),
        'step' => $this->getStepId(),
      ], ['absolute' => TRUE])->toString();
    }

    return $pane_form;
  }

}
