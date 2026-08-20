# Changelog

## 5.0.0 — 2026-08-20

Initial release.

### Added

- Posts completed Craft Commerce orders into Twinfield, as either a **sales invoice** or a
  **journal transaction**, switchable per site.
- OAuth 2.0 connection with automatic cluster resolution, token refresh and encrypted storage of
  the grant outside project config.
- Customer sync: creates and updates the Twinfield debtor (dimension type `DEB`) before posting,
  with four debtor-code strategies and an optional single guest debtor.
- Article and ledger mapping, per purchasable, per product type, and for shipping and discounts,
  with optional creation of missing Twinfield articles.
- VAT mapping by charged rate or by Commerce tax category, with separate zero-rate and fallback
  codes.
- Payment reconciliation: reads the open value and match status back out of Twinfield, moves the
  order to a configured status, and optionally records a Commerce payment.
- Credit notes for Commerce refunds, with partial refunds apportioned across the order's VAT codes.
- A connection log holding the request and response XML for every round trip, with access tokens
  redacted.
- Console commands for posting, previewing, retrying, reconciling and log housekeeping.
- `craft.twinsies` Twig variable for order confirmation pages.
- **Dutch translation** of all 296 interface strings, using Twinfield's own vocabulary
  (*administratie*, *dagboek*, *debiteur*, *grootboekrekening*, *afletteren*, *creditfactuur*).
