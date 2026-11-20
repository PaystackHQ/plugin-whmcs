## 2.4.0 (November 18, 2026)

- Restricted keys are accepted, with a permission check on save (Transactions: read only; the listed money-moving permissions are refused)
- Test/live mode checks between the key, the slot and the public key
- Webhook signing secrets (`whsec_`) for restricted-key stores
- Restricted-key problems at payment time and ignored webhooks are logged to the Gateway Log
- Customers see a generic message instead of Paystack's restricted-key error
- Removed the unused redirect checkout fallback (go=standard); checkout uses the Paystack popup.
- Secret keys are now trimmed before webhook signature checks, so stores with stray spaces around an `sk_` key now verify webhooks

## 2.3.0 (April 25, 2024)

- Upgraded to Paystack inlinejs v2
- Added support for Apple pay
- Included fees in the payment data
- Added support for merchants who pass charges to customers
- Bug fixes

## 2.2.1 (November 13, 2020)

- Fix broken payment options image link
  
## 2.2.0 (November 06, 2020)

- Add ZAR to supported currencies

## 1.0.1 (June 13, 2016)

- [fix] Add logic to avoid duplication of payment verification
- [fix] Add logic to convert back to client currency after payment
- [chore] Use Language Pay now for button prompt
- [chore] Shorten Gateway name
- Append button if `payment-btn-container` not found

## 1.0.0 (May 05, 2016)

- Initial release

