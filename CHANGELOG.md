# ParadoxLabs_StripeHyvaCheckout Changelog

## 1.1.1 - Nov 25, 2026

- Fixed stored cards disappearing from the stored card list after saving a new or edited billing
  address on Hyvä Checkout 3, leaving only "Add new card" until the next checkout update. The console showed
  "Magewire entangle: property ['storedCards'] cannot be found on component ['checkout.payment.methods']".

## 1.1.0 - Nov 23, 2026

- Added Hyvä Checkout 3.x support (3.0.1+), native to Magewire 3; Hyvä's v1bc module is not required.
- Now requires Hyvä Checkout 1.3.6 or later on the 1.x line.
- Fixed an unavailable payment method (for example, missing API keys) breaking the payment step.

## 1.0.0 - Sep 15, 2026

- Initial release: ParadoxLabs Stripe payment support for Hyvä Checkout.
- Added the embedded Stripe Payment Element payment form, supporting credit/debit cards as well as Apple Pay, Google
  Pay, and Link wallets.
- Added stored card (vault) support via ParadoxLabs_TokenBase.
- Added inline 3D Secure authentication during place order, without leaving the checkout page.
- Added customer account payment options (My Payment Options) support on Hyvä themes, for listing, adding, editing, and
  deleting stored cards, via the shared ParadoxLabs_TokenBaseHyvaCheckout module.
- Added inline reporting of place-order failures and decline reasons in checkout, in place of a generic error dialog.
- Uses the ConfirmationToken flow: the payment intent is created and confirmed server-side at place order.
- Strict CSP and Alpine CSP compliant, for Hyvä Checkout 1.3+ nonce-based CSP.
