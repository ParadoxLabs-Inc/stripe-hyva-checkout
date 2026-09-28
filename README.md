<p align="center">
    <a href="https://www.paradoxlabs.com"><img alt="ParadoxLabs" src="https://paradoxlabs.com/wp-content/uploads/2020/02/pl-logo-canva-2.png" width="250"></a>
</p>

This module adds support for Hyva Checkout to
our Stripe payment method for Magento 2.

Requirements
============

* Adobe Commerce / Magento Open Source 2.4.6 – 2.4.9 (or equivalent version of Adobe Commerce Cloud), or Mage-OS 2.0 – 3.0
* PHP 8.1, 8.2, 8.3, 8.4, or 8.5
* Hyva Checkout (separate product and license), `hyva-themes/magento2-hyva-checkout` >= 1.3
* `hyva-themes/magento2-theme-module` >= 1.3.11
* `hyva-themes/magento2-payment-icons` >= 2.0
* `paradoxlabs/stripe` ^5.0
* `paradoxlabs/tokenbase-hyva-checkout` ^1.0 (shared Hyva payment-options scaffold)

Features
========

* Place orders via Hyva Checkout, with Stripe payment
* Embedded Stripe Payment Element payment form, supporting credit/debit cards as well as Apple Pay, Google Pay, and
  Link wallets
* Uses the ConfirmationToken flow: no client secret is requested up front, and the payment intent is created and
  confirmed server-side at place order
* Supports stored cards (vault) via ParadoxLabs_TokenBase
* Handles 3D Secure authentication inline during place order, without leaving the checkout page
* Customer-account "My Payment Options" (paymentinfo) card management on Hyva themes: card list, delete, and
  add/edit via the shared `ParadoxLabs_TokenBaseHyvaCheckout` scaffold
* Strict CSP and Alpine CSP compliant, for Hyva Checkout 1.3+ nonce-based CSP

Installation and Usage
======================

In SSH at your Magento base directory, run:

    composer require paradoxlabs/stripe-hyva-checkout
    php bin/magento module:enable ParadoxLabs_StripeHyvaCheckout
    php bin/magento setup:upgrade

## Applying Updates

In SSH at your Magento base directory, run:

    composer update paradoxlabs/stripe-hyva-checkout
    php bin/magento setup:upgrade

These commands will download and apply any available updates to the module.

If you have any integrations or custom functionality based on this extension, we strongly recommend testing to ensure
they are not affected.

Error Handling and 3-D Secure Recovery
======================================

Place-order failures are handled inside `Magewire\Payment\PlaceOrderService` rather than being
rethrown. Hyva's default `AbstractPlaceOrderService::handleException()` rethrows the exception, which
aborts the Magewire request before the `order:place:paradoxlabs_stripe:error` browser event the
processor queued can reach the response. That event is what drives this module's inline 3-D Secure
recovery (`scripts.phtml`: `loadNextAction()` → `paradoxlabs_stripeNextAction` browser event →
`stripe.handleNextAction()` → re-place) and the
single-use ConfirmationToken scrub — so with the default rethrow, neither ever runs, and in
production the customer only sees a generic "page refresh" dialog.

This service therefore overrides three methods:

* `handleException()` buffers the exception instead of rethrowing, letting the request complete and
  deliver the queued event.
* `evaluateCompletion()` reports the failure through the standard messenger. A `LocalizedException`
  message (the decline reason) is shown verbatim; anything else is masked to a generic message so
  raw internals never leak.
* `canRedirect()` returns `false` while a failure is buffered, suppressing the success-page redirect
  the processor would otherwise push once the exception stops propagating.

**3-D Secure message decision:** a `requires_action` result surfaces here as a
`StripeAuthenticationRequiredException`, but it is not a decline — the client immediately runs the
challenge and re-places the order. Showing the raw exception (or a red decline) would be misleading
and could leak the intent id / client_secret, so for this exception class the message is *softened*:
a neutral, warning-styled "Confirming your payment…" note with no sensitive detail. It remains a
failure result (no success, no redirect) so the event-driven recovery still runs; a genuine failure
*after* the challenge is reported inline by `runNextAction()`. All other exceptions use the
verbatim/masked policy above.

The `order:place:paradoxlabs_stripe:error` listener also fires for ordinary declines (no
next-action): `loadNextAction()` dispatches an empty event and the listener resets without a second
message.

Known Limitations
=================

* A $0 quote with `payment_action` not set to `order` will not render the Element (`mode: 'payment'` requires a
  positive amount). This matches the Luma renderer's behavior.

Changelog
=========

Please see [CHANGELOG.md](CHANGELOG.md).

Support
=======

This module is covered by your ParadoxLabs extension support plan. If you need help, open a ticket at
[support.paradoxlabs.com](https://support.paradoxlabs.com). To renew support,
[buy an extension support plan from ParadoxLabs](https://store.paradoxlabs.com/support-renewal.html).

License
=======

This module is proprietary software, licensed under the
[ParadoxLabs software license](https://store.paradoxlabs.com/license.html). See license.txt.
