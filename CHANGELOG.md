# ParadoxLabs_StripeHyvaCheckout Changelog

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
