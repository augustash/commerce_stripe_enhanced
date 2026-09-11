<?php

namespace Drupal\commerce_stripe_enhanced\Plugin\Commerce\PaymentGateway;

use Drupal\commerce_stripe\Plugin\Commerce\PaymentGateway\StripePaymentElement as StripePaymentElementBase;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Form\FormStateInterface;
use Stripe\Exception\ApiErrorException;
use Stripe\PaymentIntent;
use Stripe\PaymentMethodConfiguration;
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
 * Declares every method type commerce_stripe ships, where the parent declares
 * only stripe_card. A type absent from the annotation can never be enabled -
 * PaymentGatewayBase::getPaymentMethodTypes() intersects the merchant's
 * configuration with this list - so a short list here is a hard ceiling on
 * what a site can offer, and silently: the checkbox simply is not on the
 * gateway form. The intent narrowing reads whatever the merchant ticked, so
 * nothing else needs to change when they tick something new.
 *
 * @CommercePaymentGateway(
 *   id = "stripe_payment_element_enhanced",
 *   label = "Stripe Payment Element (enhanced)",
 *   display_label = "Stripe Payment Element (enhanced)",
 *   payment_method_types = {
 *     "stripe_affirm", "stripe_alipay", "stripe_amazon_pay", "stripe_card",
 *     "stripe_cashapp", "stripe_klarna", "stripe_link", "stripe_paypal",
 *     "stripe_us_bank_account", "stripe_wechat_pay"
 *   },
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
   * Offers only the methods the Stripe account actually has enabled.
   *
   * A gateway plugin's method-type checkboxes come from its annotation, which
   * is a fixed list of everything the integration could ever support. That
   * makes the form a second place where "which methods do we take" is decided,
   * and the two answers drift: a method ticked here but off at Stripe is
   * offered to customers and cannot be paid with - for some types Stripe
   * refuses to create the intent at all, which takes the payment step down -
   * while a method switched on at Stripe never appears until someone
   * remembers to tick it here too.
   *
   * So the list is read from the account instead. Stripe stays the one place
   * methods are turned on and off; the only decision left on this form is
   * which of the available methods this gateway presents as a single checkout
   * option, which is a genuinely different question and the reason a site runs
   * more than one instance.
   *
   * A method already saved here but since turned off at Stripe is kept on the
   * form, labelled, rather than quietly dropped - it is still on the gateway
   * until someone unticks it, and hiding it would both conceal that and make
   * it unremovable.
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $form = parent::buildConfigurationForm($form, $form_state);

    $available = $this->accountPaymentMethods();
    if ($available === NULL) {
      // No keys yet on a new gateway, or Stripe could not be reached. Leave
      // the annotation's list in place rather than presenting no methods at
      // all, and say why it is not the account's own list.
      $note = $this->t('Could not reach Stripe to read which payment methods this account has enabled, so every method the integration supports is listed. Save the API keys first, then edit this gateway again to narrow it to the account.');
      foreach ([['payment_method_types'], ['express_checkout', 'allowed_payment_method_types']] as $parents) {
        $element = &NestedArray::getValue($form, $parents);
        if (is_array($element)) {
          $element['#description'] = isset($element['#description'])
            ? $element['#description'] . ' ' . $note
            : $note;
        }
        unset($element);
      }
      return $form;
    }

    /** @var \Drupal\commerce_stripe_enhanced\ExpressMethods $express_methods */
    $express_methods = \Drupal::service('commerce_stripe_enhanced.express_methods');

    if (isset($form['payment_method_types']['#options'])) {
      $this->limitMethodOptions(
        $form['payment_method_types'],
        $available,
        $this->configuration['payment_method_types'] ?? [],
        fn (string $key): string => $express_methods->stripeNameFromPluginId($key),
      );
    }
    if (isset($form['express_checkout']['allowed_payment_method_types']['#options'])) {
      $this->limitMethodOptions(
        $form['express_checkout']['allowed_payment_method_types'],
        $available,
        array_keys(array_filter($this->configuration['express_checkout']['allowed_payment_method_types'] ?? [])),
        fn (string $key): string => $express_methods->stripeNameFromElementKey($key),
      );
    }

    return $form;
  }

  /**
   * Narrows one checkboxes element to the methods the account has enabled.
   *
   * @param array $element
   *   The checkboxes element, by reference.
   * @param string[] $available
   *   Stripe method names the account has enabled.
   * @param string[] $saved
   *   Option keys already saved on this gateway.
   * @param callable $to_stripe_name
   *   Converts one of this element's option keys to a Stripe method name.
   */
  protected function limitMethodOptions(array &$element, array $available, array $saved, callable $to_stripe_name): void {
    foreach ($element['#options'] as $key => $label) {
      if (in_array($to_stripe_name((string) $key), $available, TRUE)) {
        continue;
      }
      if (in_array($key, $saved, TRUE)) {
        // Saved, so it is still live on this gateway - say so plainly and let
        // them untick it.
        $element['#options'][$key] = $this->t('@label - not currently enabled at Stripe', [
          '@label' => $label,
        ]);
        continue;
      }
      unset($element['#options'][$key]);
    }
  }

  /**
   * Names the payment methods the Stripe account has enabled.
   *
   * Read from the account's default payment method configuration, which is
   * what the dashboard's payment method settings write to.
   *
   * @return string[]|null
   *   Stripe method names, or NULL if the account could not be read - no keys
   *   saved yet, or Stripe unreachable.
   */
  protected function accountPaymentMethods(): ?array {
    if (empty($this->configuration['secret_key']) && empty($this->configuration['access_token'])) {
      return NULL;
    }

    try {
      $this->init();
      $configurations = PaymentMethodConfiguration::all(['limit' => 20]);
    }
    catch (ApiErrorException) {
      return NULL;
    }

    $configuration = NULL;
    foreach ($configurations->data as $candidate) {
      if (!empty($candidate->is_default)) {
        $configuration = $candidate;
        break;
      }
      $configuration ??= $candidate;
    }
    if (!$configuration) {
      return NULL;
    }

    $enabled = [];
    foreach ($configuration->toArray() as $name => $value) {
      // Every method is an object carrying a display preference; everything
      // else on the configuration is a scalar like id or name.
      if (is_array($value) && (($value['display_preference']['value'] ?? NULL) === 'on')) {
        $enabled[] = $name;
      }
    }

    return $enabled;
  }

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
