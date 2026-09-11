<?php

namespace Drupal\commerce_stripe_enhanced\Plugin\Commerce\PaymentGateway;

use Drupal\commerce_stripe\Plugin\Commerce\PaymentGateway\StripePaymentElement as StripePaymentElementBase;
use Stripe\PaymentIntent;
use Stripe\SetupIntent;

/**
 * Stripe Payment Element that keeps Affirm and saved cards at the same time.
 *
 * Stripe drops every single-use payment method — Affirm among them — from an
 * intent that carries a top-level setup_future_usage, which is what the parent
 * plugin sends whenever the gateway is configured for re-use. A store often
 * needs both: returning customers expect their card on file, and pay-over-time
 * is a conversion lever at higher price points.
 *
 * Scoping setup_future_usage to the card method resolves it — Stripe only
 * filters when the requirement applies intent-wide:
 *
 *   setup_future_usage=on_session               -> card, amazon_pay
 *   payment_method_options[card][sfu]           -> card, affirm, amazon_pay
 *
 * @see \Drupal\commerce_stripe_enhanced\EventSubscriber\StripePaymentIntentSubscriber
 *   which performs that move on the way out.
 *
 * @CommercePaymentGateway(
 *   id = "stripe_payment_element_enhanced",
 *   label = "Stripe Payment Element (enhanced)",
 *   display_label = "Stripe Payment Element (enhanced)",
 *   payment_method_types = {"stripe_card", "stripe_affirm"},
 *   forms = {
 *     "offsite-payment" = "Drupal\commerce_stripe\PluginForm\OffsiteRedirect\PaymentOffsiteForm",
 *   },
 *   credit_card_types = {
 *     "amex", "dinersclub", "discover", "jcb", "maestro", "mastercard",
 *     "visa", "unionpay"
 *   },
 *   requires_billing_information = FALSE,
 * )
 */
class StripePaymentElement extends StripePaymentElementBase {

  /**
   * {@inheritdoc}
   *
   * Restores the top-level setup_future_usage that the subscriber moved under
   * payment_method_options.
   *
   * The parent decides whether to attach a card to its Stripe customer by
   * reading setup_future_usage straight off the retrieved intent, so a value
   * living only at card scope reads as absent and the card is never saved —
   * quietly trading the problem rather than fixing it. Hoisting it back is
   * sound because nothing in the module writes a retrieved intent back to the
   * API; every update goes through PaymentIntent::update() with an explicit
   * payload, so this value stays local to the request.
   *
   * @see \Drupal\commerce_stripe\Plugin\Commerce\PaymentGateway\StripePaymentElement::attachCustomerToStripePaymentMethod()
   */
  public function getIntent(?string $intent_id): PaymentIntent|SetupIntent|null {
    $intent = parent::getIntent($intent_id);

    if ($intent instanceof PaymentIntent && empty($intent->setup_future_usage)) {
      $scoped = $intent->payment_method_options->card->setup_future_usage ?? NULL;
      if (!empty($scoped)) {
        $intent->setup_future_usage = $scoped;
      }
    }

    return $intent;
  }

}
