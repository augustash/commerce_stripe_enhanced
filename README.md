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

**One offer per wallet, worked out rather than configured.** The express element and the
payment pane are configured on separate screens and nothing upstream ties them: the element
shows whatever is ticked in the gateway's `express_checkout.allowed_payment_method_types`, the
pane offers whatever that gateway names in `payment_method_types`. Turn Amazon Pay, PayPal,
Klarna or Link on in both and the customer meets it as a one-tap button above the form and
again inside the form, having already walked past it. The button is the better offer, so it
keeps the method.

Nothing about this is a setting, because nothing about it needs to be asked. Which methods the
express element offers is already the gateway's own configuration; which of those have a
duplicate to remove is derived — Apple Pay and Google Pay have no payment method type plugin,
because they are a card presented by a wallet rather than methods of their own, so narrowing an
intent to `card` cannot remove them and they are turned off through the Payment Element's
`wallets` option instead. Every other express-capable method does have a plugin, and the
intent's `payment_method_types` governs it. A hand-kept list of "these are the wallets" would
be a third copy of a fact the code can already read, and the first one to go stale.

**The Payment Element sits with its radio.** Upstream renders it from a separate pane, which
lands it beside the list rather than under the option that asked for it — and because the
payment radios refresh over AJAX and that pane does not, a stale element was left on the page
after switching methods. Nesting it puts contrib's own refresh in charge of both.

**A method Stripe won't take the amount for isn't offered.** Stripe enforces a minimum and
maximum per method and refuses to create the intent outside them — and that refusal lands as the
payment step builds, so the customer who picked the option gets a broken step, not a message.
Affirm is $35–$30,000 USD. Upstream never hits this because `automatic_payment_methods` resolves
per order and simply omits the method; naming the types explicitly, which is what makes one
payment radio mean one method, is what loses that. So the option is withheld instead, and the
intent drops the method where the option had something else to offer.

Those bounds live in code (`PaymentMethodLimits`), not configuration: they are Stripe's rules
rather than a site's decision, they are exposed by no API — a payment method configuration
carries only `available` and a display preference, so the numbers exist only in the rejection
message — and as config they would be hand-maintained, and drift, per site. A site's *own* rule
is a different thing and belongs in configuration, as an order-total condition on the gateway:
"we don't offer financing under $50" is a business call sitting above whatever Stripe allows.

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

**The rest of the payment step saves before the card is confirmed.** Upstream collects the card
on a step of its own, after the payment step has submitted. Collected on the payment step
instead, confirming sends the customer to Stripe from a form Drupal never receives, so on a
card order everything else on that step was lost: the billing address and "same as shipping",
order notes, any opt-in. Place Order now submits the step's panes over AJAX first — the card
typed into the element survives, and a validation error comes back as a message — and confirms
only once that has saved.

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

**Stripe stays the one place methods are switched on.** The gateway declares every payment
method type commerce_stripe ships, where upstream's declares only `stripe_card` — a type absent
from the annotation can never be enabled, and silently, since the checkbox is simply not on the
form. But the form then narrows that list to what the Stripe account actually has enabled, read
from its default payment method configuration, so the two can't disagree: a method that is off
at Stripe is not offerable in Drupal, and one switched on at Stripe appears here without anyone
remembering to mirror it.

What's left to decide on the gateway form is only which of the *available* methods this gateway
presents as a single checkout option — a different question, and the reason a site runs more
than one instance (one for cards, one for Affirm, each its own radio with its own label).

A method already saved but since turned off at Stripe stays on the form, labelled "not
currently enabled at Stripe", rather than vanishing: it is still live on the gateway until
someone unticks it, and hiding it would conceal that and make it unremovable. With no keys
saved yet, or Stripe unreachable, the full list is shown with a note saying why.

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
| Hide the card consent notice | off | The site states its own terms for charging a saved card. Stripe's notice under the card fields is that consent; hiding it without saying the same elsewhere leaves future charges on a saved card without it. |
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
