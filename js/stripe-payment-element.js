/**
 * @file
 * Replaces commerce_stripe's Payment Element behavior.
 *
 * Upstream binds a submit listener to the whole checkout form on every mount,
 * closing over that mount's Elements instance. That is fine while the element
 * renders once, but we collect the card on the payment step, where switching
 * the payment radio refreshes the pane over AJAX. The form survives that
 * refresh and its listeners with it, so each switch left another listener
 * holding an Elements instance whose node had been destroyed - and on submit
 * the stale one threw "We could not retrieve data from the specified Element".
 *
 * So bind to the form once and read the live mount at submit time instead.
 */

((Drupal, drupalSettings, once, Stripe, $) => {
  /**
   * The current mount, replaced whenever the pane re-renders.
   */
  let active = null;

  /**
   * Resolves the save in flight when the server reports on it.
   */
  let settleSave = null;

  /**
   * Receives the server's report on a step save.
   */
  Drupal.AjaxCommands.prototype.commerceStripeEnhancedStepSaved = (
    ajax,
    response,
  ) => {
    if (settleSave) {
      settleSave(response.saved);
      settleSave = null;
    }
  };

  /**
   * Submits the rest of the step to Drupal before the card leaves for Stripe.
   *
   * Confirming sends the customer to Stripe from a form Drupal never receives,
   * so without this every other input on the step - the billing address,
   * "same as shipping", order notes - was dropped on a card order.
   *
   * @param {HTMLFormElement} form
   *   The checkout form.
   *
   * @return {Promise<boolean>}
   *   Whether the step saved.
   */
  function saveStep(form) {
    // Found by name: nested inside the pane, its data-drupal-selector carries
    // the pane's parents and so varies by site.
    const name = drupalSettings.commerceStripeEnhanced?.saveStepButton;
    const trigger = name && form.querySelector(`[name="${name}"]`);
    // A flow without the save button behaves as upstream did.
    if (!trigger) {
      return Promise.resolve(true);
    }
    return new Promise((resolve) => {
      settleSave = resolve;
      $(trigger).trigger('commerce-stripe-enhanced-save-step');
    });
  }

  /**
   * Confirms the payment for whichever mount is live when the form submits.
   */
  async function confirmActivePayment(event) {
    // The pane is gone - the customer picked PayPal or Affirm, and this is an
    // ordinary Drupal submit that must be left alone.
    if (!active || !document.getElementById(active.settings.elementId)) {
      return;
    }

    event.preventDefault();
    const { stripe, elements, settings, button } = active;
    const release = () => {
      if (button) {
        button.disabled = false;
      }
    };
    if (button) {
      button.disabled = true;
    }

    // Surface a card the customer has not finished before anything is saved,
    // rather than saving the step and then refusing the payment.
    if (settings.showPaymentForm) {
      const { error } = await elements.submit();
      if (error) {
        release();
        return;
      }
    }

    if (!(await saveStep(event.target))) {
      release();
      return;
    }

    const confirm =
      settings.intentType === 'setup' ? stripe.confirmSetup : stripe.confirmPayment;
    const options = {
      confirmParams: { return_url: settings.returnUrl },
      redirect: 'always',
    };
    // With the form shown Stripe reads the card out of the mounted element;
    // without it there is a saved method behind the client secret already.
    if (settings.showPaymentForm) {
      options.elements = elements;
    }
    else {
      options.clientSecret = settings.clientSecret;
    }

    try {
      const result = await confirm(options);
      if (result.error) {
        Drupal.commerceStripe.displayError(result.error.message);
        if (button) {
          button.disabled = false;
        }
      }
    }
    catch (error) {
      Drupal.commerceStripe.displayError(error.message);
      if (button) {
        button.disabled = false;
      }
    }
  }

  Drupal.behaviors.commerceStripePaymentElement = {
    attach(context) {
      if (
        !drupalSettings.commerceStripePaymentElement ||
        !drupalSettings.commerceStripePaymentElement.publishableKey
      ) {
        return;
      }

      const settings = drupalSettings.commerceStripePaymentElement;

      function processStripeForm(item) {
        const stripeForm = item.closest('form');
        const button = stripeForm.querySelector(
          `input[data-drupal-selector="${settings.buttonId}"],button[data-drupal-selector="${settings.buttonId}"]`,
        );
        const stripe = Stripe(settings.publishableKey, {
          apiVersion: settings.apiVersion,
        });

        let elements = null;
        if (settings.showPaymentForm) {
          elements = stripe.elements(settings.createElementsOptions);
          const paymentElement = elements.create(
            'payment',
            settings.paymentElementOptions,
          );
          paymentElement.mount(`#${settings.elementId}`);
          paymentElement.on('ready', () => {
            button.disabled = false;
            button.classList.remove('is-disabled');
          });
          // Never strand the customer on a dead button: without this a failed
          // mount leaves the only way forward permanently disabled.
          paymentElement.on('loaderror', (event) => {
            button.disabled = false;
            button.classList.remove('is-disabled');
            Drupal.commerceStripe.displayError(
              event.error?.message ||
                Drupal.t('The payment form could not be loaded. Please try again.'),
            );
          });
        }
        else {
          button.disabled = false;
          button.classList.remove('is-disabled');
        }

        active = { stripe, elements, settings, button };
        once('stripe-submit', stripeForm).forEach((form) => {
          form.addEventListener('submit', confirmActivePayment);
        });
      }

      once('stripe-processed', `#${settings.elementId}`, context).forEach(
        processStripeForm,
      );
    },
  };
})(Drupal, drupalSettings, once, window.Stripe, jQuery);
