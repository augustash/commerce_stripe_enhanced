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

    // Apple Pay and Google Pay are not payment method types - they ride on
    // card, so narrowing the intent to card leaves them showing as tabs. They
    // are offered once, on their own terms, by the express element at the head
    // of checkout; re-offering them here asks a question already answered.
    if (isset($settings['commerceStripePaymentElement']['paymentElementOptions'])) {
      $settings['commerceStripePaymentElement']['paymentElementOptions']['wallets'] = [
        'applePay' => 'never',
        'googlePay' => 'never',
      ];
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
