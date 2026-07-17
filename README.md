[![Latest Stable Version](https://poser.pugx.org/paradoxlabs/stripe-hyva-checkout/v/stable)](https://packagist.org/packages/paradoxlabs/stripe-hyva-checkout)
[![License](https://poser.pugx.org/paradoxlabs/stripe-hyva-checkout/license)](https://packagist.org/packages/paradoxlabs/stripe-hyva-checkout)
[![Total Downloads](https://poser.pugx.org/paradoxlabs/stripe-hyva-checkout/downloads)](https://packagist.org/packages/paradoxlabs/stripe-hyva-checkout)

<p align="center">
    <a href="https://www.paradoxlabs.com"><img alt="ParadoxLabs" src="https://paradoxlabs.com/wp-content/uploads/2020/02/pl-logo-canva-2.png" width="250"></a>
</p>

This module adds support for Hyva Checkout to
our [Stripe payment method for Magento 2](https://github.com/ParadoxLabs-Inc/stripe).

Requirements
============

* Adobe Commerce / Magento Open Source 2.4.6 – 2.4.9 (or equivalent version of Adobe Commerce Cloud), or Mage-OS 2.0 – 3.0
* PHP 8.1, 8.2, 8.3, 8.4, or 8.5
* Hyva Checkout (separate product and license), `hyva-themes/magento2-hyva-checkout` >= 1.3
* `hyva-themes/magento2-theme-module` >= 1.3.11
* `hyva-themes/magento2-payment-icons` >= 2.0
* `paradoxlabs/stripe` ^5.0

Features
========

* Place orders via Hyva Checkout, with Stripe payment
* Embedded Stripe Payment Element payment form, supporting credit/debit cards as well as Apple Pay, Google Pay, and
  Link wallets
* Uses the ConfirmationToken flow: no client secret is requested up front, and the payment intent is created and
  confirmed server-side at place order
* Supports stored cards (vault) via ParadoxLabs_TokenBase
* Handles 3D Secure authentication inline during place order, without leaving the checkout page
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

Known Limitations (TODO)
========================

Pre-release caveats — verify before relying on these in production:

* **TODO: Live install verification.** This module has not yet been exercised against a running Hyva Checkout
  install. Two assumptions need confirmation there: Magewire `$wire` action return values resolving through the
  call promise (standard Livewire v2 semantics), and `dispatchBrowserEvent` delivery (the template defensively
  listens on both `window` and `document`).
* **TODO: Browser QA of the 3D Secure and wallet matrix** (challenge, challenge-abandoned, decline-after-challenge;
  Apple Pay / Google Pay / Link).
* **TODO: Card management on Hyva customer account pages.** The Luma-based TokenBase card-management link is removed
  from the Hyva account navigation; there is no Hyva-native replacement yet.
* A $0 quote with `payment_action` not set to `order` will not render the Element (`mode: 'payment'` requires a
  positive amount). This matches the Luma renderer's behavior.

Changelog
=========

Please see [CHANGELOG.md](https://github.com/ParadoxLabs-Inc/stripe-hyva-checkout/blob/master/CHANGELOG.md).

Support
=======

This module is provided free and without support of any kind. You may report issues you've found in the module, and we
will address them as we are able, but **no support will be provided here.**

**DO NOT include any API keys, credentials, or customer-identifying in issues, pull requests, or comments. Any
personally identifying information will be deleted on sight.**

If you need personal support services,
please [buy an extension support plan from ParadoxLabs](https://store.paradoxlabs.com/support-renewal.html), then open a
ticket at [support.paradoxlabs.com](https://support.paradoxlabs.com).

Contributing
============

Please feel free to submit pull requests with any contributions. We welcome and appreciate your support, and will
acknowledge contributors.

This module is maintained by ParadoxLabs, a Magento solutions provider. We make no guarantee of accepting contributions,
especially any that introduce architectural changes.

License
=======

This module is licensed
under [APACHE LICENSE, VERSION 2.0](https://github.com/ParadoxLabs-Inc/stripe/blob/master/LICENSE).
