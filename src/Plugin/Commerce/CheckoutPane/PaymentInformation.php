<?php

namespace Drupal\commerce_stripe_enhanced\Plugin\Commerce\CheckoutPane;

use Drupal\commerce_payment\Plugin\Commerce\CheckoutPane\PaymentInformation as PaymentInformationBase;
use Drupal\Component\Serialization\Json;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\Core\Render\Element;
use Drupal\Core\Render\Markup;
use Drupal\Core\Url;

/**
 * The payment information pane, with the saved cards made manageable.
 *
 * Carries no plugin annotation of its own: it replaces the class of commerce's
 * payment_information pane rather than adding a pane beside it, which is what
 * that pane's own documentation asks for. A site that defined its own pane in
 * its place extends this class and names its pane id in
 * commerce_stripe_enhanced.settings:payment_pane_id.
 *
 * @see commerce_stripe_enhanced_commerce_checkout_pane_info_alter()
 */
class PaymentInformation extends PaymentInformationBase {

  /**
   * {@inheritdoc}
   */
  public function buildPaneForm(array $pane_form, FormStateInterface $form_state, array &$complete_form) {
    $pane_form = parent::buildPaneForm($pane_form, $form_state, $complete_form);
    $this->clearUnchosenDefault($pane_form, $form_state);
    $pane_form['#after_build'][] = [
      static::class,
      'addImagesToPaymentMethods',
    ];
    $pane_form['#after_build'][] = [
      static::class,
      'addRemoveLinksToPaymentMethods',
    ];
    // The step the Remove link comes back to. Built here rather than in the
    // after build, which also runs on an AJAX rebuild, where the current
    // request is /system/ajax and not the step at all.
    $pane_form['#remove_destination'] = Url::fromRoute('commerce_checkout.form', [
      'commerce_order' => $this->order->id(),
      'step' => $this->getStepId(),
    ])->toString();
    return $pane_form;
  }

  /**
   * Leaves the payment methods unanswered until the customer answers them.
   *
   * The parent preselects a method, which reads as a decision the customer did
   * not make and costs a Stripe intent on every arrival at the step - one that
   * is wrong the moment they pick anything else, and abandoned if they pick
   * nothing. A stored method is the exception: that choice was made already.
   *
   * @param array $pane_form
   *   The pane form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  protected function clearUnchosenDefault(array &$pane_form, FormStateInterface $form_state) {
    if (empty($pane_form['payment_method']['#options'])) {
      return;
    }

    $parents = array_merge($pane_form['#parents'], ['payment_method']);
    $input = $form_state->getUserInput() ?? [];
    $chosen = NestedArray::getValue($input, $parents);
    if ($chosen) {
      return;
    }

    $default = $pane_form['payment_method']['#default_value'] ?? NULL;
    $option = $pane_form['#payment_options'][$default] ?? NULL;
    if ($option && $option->getPaymentMethodId()) {
      return;
    }

    $pane_form['payment_method']['#default_value'] = NULL;
    // Without this the step submits with no method and the parent's submit
    // handler indexes an option that is not there.
    $pane_form['payment_method']['#required'] = TRUE;

    // These belong to whichever method is chosen, so they have nothing to
    // describe yet.
    foreach (['add_payment_method', 'billing_information'] as $key) {
      if (isset($pane_form[$key])) {
        $pane_form[$key]['#access'] = FALSE;
      }
    }
  }

  /**
   * {@inheritdoc}
   *
   * The parent clears stale input for add_payment_method only. A gateway that
   * stores no payment method - Affirm, PayPal - carries its billing form at
   * pane level instead, so that input survived the switch and the rebuilt form
   * read it as submitted: "same as shipping" arrived with no value and came up
   * unticked, where a new billing profile should default to copying shipping.
   */
  public static function clearValues(array $element, FormStateInterface $form_state) {
    $element = parent::clearValues($element, $form_state);
    $triggering_element = $form_state->getTriggeringElement();
    if ($triggering_element && end($triggering_element['#parents']) === 'payment_method') {
      $user_input = &$form_state->getUserInput();
      NestedArray::unsetValue($user_input, array_merge($element['#parents'], ['billing_information']));
    }
    return $element;
  }

  /**
   * Puts each gateway's mark beside its radio.
   *
   * Sourced from a "module:directory" pair in
   * commerce_stripe_enhanced.settings:payment_method_images, holding a PNG per
   * gateway id. Gateway ids are a site's own, so a site pointing this at its
   * own module can name the files after the gateways it actually configured.
   *
   * @param array $element
   *   The pane form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return array
   *   The pane form.
   */
  public static function addImagesToPaymentMethods(array $element, FormStateInterface $form_state) {
    if (empty($element['payment_method'])) {
      return $element;
    }

    $source = \Drupal::config('commerce_stripe_enhanced.settings')->get('payment_method_images');
    if (empty($source)) {
      return $element;
    }
    [$module, $directory] = array_pad(explode(':', $source, 2), 2, 'images');
    $module_list = \Drupal::service('extension.list.module');
    if (!$module_list->exists($module)) {
      return $element;
    }
    $base = $module_list->getPath($module) . '/' . trim($directory, '/');

    foreach (Element::children($element['payment_method']) as $key) {
      /** @var \Drupal\commerce_payment\PaymentOption $payment_option */
      $payment_option = $element['#payment_options'][$key] ?? NULL;
      if (!$payment_option) {
        continue;
      }
      $image_path = $base . '/' . $payment_option->getPaymentGatewayId() . '.png';
      if (file_exists($image_path)) {
        $element['payment_method'][$key]['#title'] = Markup::create(
          $element['payment_method'][$key]['#title'] . ' <img src="' . base_path() . $image_path . '" />'
        );
      }
    }

    return $element;
  }

  /**
   * Offers a Remove link on the cards the customer already has on file.
   *
   * The account has a payment methods page, but a card that is wrong or dead
   * announces itself here, at the moment it is being chosen, and sending
   * someone out of checkout to deal with it loses the checkout.
   *
   * The link goes to commerce's own delete form, which owns the confirmation,
   * the access check and - through the gateway plugin - detaching the card at
   * Stripe. It hangs off the radio rather than sitting inside its label,
   * because inside the label a click on it also selects the card being
   * removed.
   *
   * @param array $element
   *   The pane form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return array
   *   The pane form.
   */
  public static function addRemoveLinksToPaymentMethods(array $element, FormStateInterface $form_state) {
    if (empty($element['payment_method'])) {
      return $element;
    }

    $storage = \Drupal::entityTypeManager()->getStorage('commerce_payment_method');
    foreach (Element::children($element['payment_method']) as $key) {
      /** @var \Drupal\commerce_payment\PaymentOption $payment_option */
      $payment_option = $element['#payment_options'][$key] ?? NULL;
      // Anything without one is an offer to add a card, not a card.
      if (!$payment_option || !$payment_option->getPaymentMethodId()) {
        continue;
      }
      /** @var \Drupal\commerce_payment\Entity\PaymentMethodInterface $payment_method */
      $payment_method = $storage->load($payment_option->getPaymentMethodId());
      if (!$payment_method || !$payment_method->access('delete')) {
        continue;
      }

      $label = t('Remove @payment_method', [
        '@payment_method' => $payment_option->getLabel(),
      ]);
      $url = $payment_method->toUrl('delete-form', [
        'query' => ['destination' => $element['#remove_destination']],
        'attributes' => [
          'class' => ['payment-method-remove', 'use-ajax'],
          'title' => $label,
          'data-dialog-type' => 'modal',
          'data-dialog-options' => Json::encode(['width' => 480]),
        ],
      ]);
      // The cross is the whole visible control, so the name a screen reader
      // reads is carried alongside it - and names the card, since 'Remove' on
      // its own says nothing about which one.
      $title = [
        'mark' => [
          '#type' => 'html_tag',
          '#tag' => 'span',
          '#value' => '&times;',
          '#attributes' => [
            'class' => ['payment-method-remove__mark'],
            'aria-hidden' => 'true',
          ],
        ],
        'label' => [
          '#type' => 'html_tag',
          '#tag' => 'span',
          '#value' => $label,
          '#attributes' => ['class' => ['visually-hidden']],
        ],
      ];
      // #field_suffix rather than #suffix: the latter is applied after the
      // element's own theme wrapper, which would drop the link out of the
      // option's box and onto a line of its own beneath it.
      $element['payment_method'][$key]['#field_suffix'] = Link::fromTextAndUrl($title, $url)->toString();
    }
    $element['#attached']['library'][] = 'core/drupal.dialog.ajax';

    return $element;
  }

}
