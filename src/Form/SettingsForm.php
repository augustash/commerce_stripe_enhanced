<?php

namespace Drupal\commerce_stripe_enhanced\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configures the seams this module needs to know about a site.
 *
 * Everything here is either a name this site chose - a pane id, a field - or a
 * presentation call on the express wallets. The behaviour fixes have no setting
 * because there is no sensible way to want them off.
 */
class SettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'commerce_stripe_enhanced_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['commerce_stripe_enhanced.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('commerce_stripe_enhanced.settings');

    $form['payment_pane_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Payment information pane ID'),
      '#default_value' => $config->get('payment_pane_id'),
      '#required' => TRUE,
      '#description' => $this->t("Commerce's own pane is <code>payment_information</code>. Change this only if this site defines its own pane in its place; that pane's class must extend this module's."),
    ];

    $form['shipping_phone_field'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Shipping profile phone field'),
      '#default_value' => $config->get('shipping_phone_field'),
      '#description' => $this->t('The field on the customer profile that receives the phone number a wallet collected. Commerce ships no such field, so leave this empty unless the site added one (for example <code>field_phone</code>). Empty means the number is discarded, which is how commerce_stripe behaves on its own.'),
    ];

    $form['hide_card_terms'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Hide the card consent notice'),
      '#default_value' => $config->get('hide_card_terms'),
      '#description' => $this->t('Stripe prints a notice under the card fields when the card is saved for later, telling the customer they allow future charges. Saving a card for future use needs that consent, so hide it only where the site states equivalent terms itself.'),
    ];

    $form['payment_method_images'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Payment method image source'),
      '#default_value' => $config->get('payment_method_images'),
      '#description' => $this->t('A <code>module:directory</code> pair holding one PNG per payment gateway ID, shown beside that gateway&rsquo;s radio. Gateway IDs are this site&rsquo;s own, so point this at a module whose images are named for them. Empty shows no images.'),
    ];

    $form['express'] = [
      '#type' => 'details',
      '#title' => $this->t('Express checkout'),
      '#open' => TRUE,
      '#tree' => TRUE,
      '#description' => $this->t('commerce_stripe offers the wallets on the cart form alone. A customer can reach checkout without passing a cart, and for them the wallets would not exist.'),
    ];
    $form['express']['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Offer the express wallets in checkout'),
      '#default_value' => $config->get('express.enabled'),
    ];
    $form['express']['step'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Checkout step'),
      '#default_value' => $config->get('express.step'),
      '#description' => $this->t('Leave empty to use the first step a customer lands on, which is almost always right: confirming a wallet clears the order&rsquo;s checkout data and jumps to review, so offering one after an address form discards the address just entered. Name a step only deliberately &mdash; <code>login</code>, say, to offer a wallet in place of signing in.'),
      '#states' => [
        'visible' => [':input[name="express[enabled]"]' => ['checked' => TRUE]],
      ],
    ];
    $form['express']['title'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Section heading'),
      '#default_value' => $config->get('express.title'),
      '#states' => [
        'visible' => [':input[name="express[enabled]"]' => ['checked' => TRUE]],
      ],
    ];
    $form['express']['divider_label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Divider label'),
      '#default_value' => $config->get('express.divider_label'),
      '#description' => $this->t('Sits between the wallets and the form below them, naming the choice the two represent. Without it they read as two things to do in order rather than two ways to start. Empty renders no divider.'),
      '#states' => [
        'visible' => [':input[name="express[enabled]"]' => ['checked' => TRUE]],
      ],
    ];
    $form['express']['button_height'] = [
      '#type' => 'number',
      '#title' => $this->t('Button height'),
      '#default_value' => $config->get('express.button_height'),
      '#min' => 40,
      '#max' => 55,
      '#description' => $this->t('Stripe accepts 40&ndash;55. Match whatever button the wallets sit beside. Empty leaves Stripe&rsquo;s default.'),
    ];
    $form['express']['wallets_always'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show Apple Pay and Google Pay on every browser'),
      '#default_value' => $config->get('express.wallets_always'),
      '#description' => $this->t('Stripe&rsquo;s default shows each wallet only on the browser that owns it, so on any other one it never appears &mdash; which reads as unavailable rather than unsupported. The cost is that a customer not signed in to that wallet gets a sign-in flow rather than a one-tap payment.'),
    ];
    $form['express']['overflow_never'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show every wallet rather than an overflow menu'),
      '#default_value' => $config->get('express.overflow_never'),
      '#description' => $this->t('Stripe collapses the extras behind a menu that starts closed. A wallet nobody can see is a wallet nobody picks.'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $express = $form_state->getValue('express');
    $this->config('commerce_stripe_enhanced.settings')
      ->set('payment_pane_id', trim($form_state->getValue('payment_pane_id')))
      ->set('shipping_phone_field', trim($form_state->getValue('shipping_phone_field')))
      ->set('payment_method_images', trim($form_state->getValue('payment_method_images')))
      ->set('hide_card_terms', (bool) $form_state->getValue('hide_card_terms'))
      ->set('express.enabled', (bool) $express['enabled'])
      ->set('express.step', trim($express['step']))
      ->set('express.title', $express['title'])
      ->set('express.divider_label', $express['divider_label'])
      ->set('express.button_height', $express['button_height'] === '' ? NULL : (int) $express['button_height'])
      ->set('express.wallets_always', (bool) $express['wallets_always'])
      ->set('express.overflow_never', (bool) $express['overflow_never'])
      ->save();

    parent::submitForm($form, $form_state);
  }

}
