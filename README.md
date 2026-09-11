# Commerce Stripe Enhanced

Closes the gaps between [commerce_stripe](https://www.drupal.org/project/commerce_stripe) and
a real storefront. Everything here was built against a live Stripe integration and is either a
behaviour upstream gets wrong or a placement upstream does not offer.

## What it does

**Affirm alongside saved cards.** Stripe drops every single-use method — Affirm, Klarna,
WeChat Pay — from an intent carrying a top-level `setup_future_usage`, which is what upstream
sends whenever the gateway is configured for re-use. The gateway plugin scopes that request to
the card method instead, so both survive, and hoists it back where upstream reads it so a card
is still attached to the Stripe customer.

**One method per option.** Every radio on the payment step is a payment method with its own
form, so an unfiltered intent turns "Credit Card" into a second menu offering Affirm and two
wallets — a category pretending to be a choice. The intent is narrowed to the methods the
gateway actually offers, read off its own `payment_method_types`, so a second gateway instance
is a config change rather than a code one.

**The Payment Element sits with its radio.** Upstream renders it from a separate pane, which
lands it beside the list rather than under the option that asked for it — and because the
payment radios refresh over AJAX and that pane does not, a stale element was left on the page
after switching methods. Nesting it puts contrib's own refresh in charge of both.

**Nothing is preselected.** Upstream picks a method for the customer, which reads as a decision
they did not make and spends a Stripe intent on every arrival at the step — wrong the moment
they pick anything else, abandoned if they never pick at all. A card already on file is the
exception: that choice was made.

**A saved card the customer can remove.** A card that is wrong or dead announces itself at the
moment it is being chosen, and sending someone to their account page to deal with it loses the
checkout. The link goes to commerce's own delete form, which owns the confirmation, the access
check and detaching the card at Stripe; a predelete hook releases the card from any draft order
still holding it, along with the intent minted against it.

**Express wallets in checkout, not only on the cart.** Upstream offers them on the cart form
alone, gated on `enable_on_cart`. A customer can reach checkout without ever seeing a cart, and
for them the wallets do not exist. See the warning under Configuration before moving them.

**A Payment Element that survives an AJAX refresh.** Upstream binds a submit listener per
mount, so each refresh of the payment pane leaves another listener holding a destroyed
Elements instance — and on submit the stale one throws "We could not retrieve data from the
specified Element". Ours binds once and reads the live mount at submit time.

## Installing

```
composer require augustash/commerce_stripe_enhanced
drush en commerce_stripe_enhanced
```

Then set the gateway's plugin to **Stripe Payment Element (enhanced)**
(`stripe_payment_element_enhanced`) — it subclasses upstream's and adds no settings of its own,
so an existing gateway keeps its configuration when repointed.

A site with no custom checkout flow is done: the module points commerce's stock
`multistep_default` flow and `payment_information` pane at its own classes, leaving the ids,
the config that places them and any existing flow untouched.

## Configuration

`/admin/commerce/config/stripe-enhanced`. Everything there is either a name the site chose or a
presentation call on the wallets; the behaviour fixes have no setting because there is no
sensible way to want them off.

| Setting | Default | When to change it |
|---|---|---|
| Payment information pane ID | `payment_information` | The site defined its own pane in commerce's place. That pane's class must extend this module's `PaymentInformation`. |
| Shipping profile phone field | *(empty)* | The site added a phone field to the customer profile and wants the number a wallet collected. Commerce ships no such field; empty discards it, as upstream does. |
| Payment method image source | *(empty)* | A `module:directory` pair holding one PNG per gateway ID, shown beside that gateway's radio. Gateway IDs are the site's own, so point this at a module whose images are named for them. |
| Express: checkout step | *(empty — derived)* | **Read this before setting it.** Empty means the first step a customer lands on. |

Confirming a wallet calls `clearOrderCheckoutData()` and moves the order to review, because a
wallet supplies its own name, address and delivery choice and upstream treats that as
authoritative. That is right when the wallet is the entry point and destructive anywhere else:
offered after an address form, it discards the address just entered. Name a step only
deliberately — `login`, say, to offer a wallet in place of signing in.

## Using it from a custom checkout flow

A site with its own flow plugin pulls the payment behaviour in directly. None of the module's
own step structure comes with it:

```php
use Drupal\commerce_stripe_enhanced\StripePaymentElementFlowTrait;

class MyCheckoutFlow extends MultistepDefault {

  use StripePaymentElementFlowTrait;

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
```

A site that renamed `payment_information` also extends the module's pane class from its own,
and names its pane id in settings.

## Theming

The module emits these and styles none of them — a storefront's checkout has its own type scale
and spacing, and a payment form that half-matches reads worse than one that does not try:

| Class | On |
|---|---|
| `.checkout-pane-express` | The express wallets section at the head of checkout |
| `.checkout-divider` | The "or" between the wallets and the form below them |
| `.payment-method-details` | The fieldset holding the Payment Element under its radio |
| `.payment-method-child` | The parts of the payment pane belonging to the chosen method |
| `.payment-method-remove` | The Remove link on a saved card |

## Required patch

The multiple-Express-Checkout-element fix is not in commerce_stripe 2.2.1. Upstream keys the
element's settings on one flat `drupalSettings` key, so a page carrying more than one element —
a cart page listing several carts — keeps only the last one attached and every other container
renders empty. Apply the patch in `patches/` until it lands upstream.
