# Twinsies — build plan

Status as of 2026-08-23: **built, 155 integration checks green, control panel smoke-tested,
docs written, promos rendered, marketing site live locally, tagged `5.0.0` locally.**
Not yet pushed to a remote, on Packagist, or listed in the Craft Console.

## Decisions

Taken 2026-08-20:

| Decision | Choice | Why |
| --- | --- | --- |
| Posting shape | **Both, switchable** | Twinfield's invoicing module is a separate product. Shops that already send their own invoices need journal postings; shops that want Twinfield to print and chase need sales invoices. Roughly an even split in NL. |
| Licensing | **One paid edition, $149** | No edition gating anywhere. Accounting integrations carry more support load than most, and the NL market is small enough that volume will be low. |
| Scope | Customer sync, article mapping, payment pull, credit notes — all four | Each is load-bearing: without customer sync every order fails on a missing debtor; without mapping every line lands in one catch-all account. |

## Phases

- [x] **0 — Protocol.** Read the live WSDLs off a cluster host, the official docs, and the
      `php-twinfield` reference client. Pinned: SOAP shape, SOAPAction, header namespace, OAuth
      endpoints and scopes, cluster resolution, finder types, the `result="0"` error convention,
      the sales-invoice and transaction XML, and the `<openvalue>`/`<valueopen>` discrepancy.
- [x] **1 — Foundations.** Package, schema (5 tables), `helpers\Xml` / `Amounts` / `Dates`,
      `models\Settings`.
- [x] **2 — Connection.** OAuth code flow, encrypted grant outside project config, cluster
      resolution, token refresh, `services\Api` transport with the 401 retry and bounded backoff.
- [x] **3 — Catalogues.** `services\Meta` over the finder, so the settings screen is dropdowns fed
      from the merchant's real administration rather than free-text boxes.
- [x] **4 — Customers.** `DEB` dimension sync, four code strategies, guest debtor.
- [x] **5 — Mapping.** Article / revenue account / VAT code resolution with a fallback chain, the
      mapping screen, optional article creation.
- [x] **6 — Documents.** `services\Documents::build()` for both modes and both kinds, included-tax
      handling, adjustment lines, reconciliation against `order.totalPrice`.
- [x] **7 — Posting.** `services\Sync`, idempotency index, per-order mutex, queue job, triggers,
      credit notes per refund transaction.
- [x] **8 — Payments.** `services\Reconcile`, self-queueing sweep, order status change, optional
      Commerce payment.
- [x] **9 — Surfaces.** Documents / Mapping / Log screens, order-edit panel, settings screen,
      four console controllers, Twig variable.
- [x] **10 — Verification.** 155 integration checks against a scripted Twinfield; control panel
      smoke-tested with a real session; settings round-trip through project config confirmed.
- [x] **11 — Dutch.** All 330 strings translated in Twinfield's own vocabulary, verified by
      `tests/tools/translations.php` (placeholders and code spans exact, emphasis runs balanced)
      and rendered end to end with the control panel set to `nl`.
- [x] **12 — Release prep.** Five docs pages with front matter, seven Plugin Store promo slides,
      and the marketing site at `justinholt.com/plugins/craft-twinsies` (29 leakage checks green,
      trademark disclaimer set). Tagged `5.0.0`.

## What is not proven

**No document has been posted to a live Twinfield administration.** The suite scripts Twinfield's
answers, so it proves the envelope, the error handling, the retries and the idempotency — not that
Twinfield accepts these documents. Before release, the whole thing needs a run against a real
Twinfield trial account:

1. Connect, list offices, pick one.
2. Post a concept sales invoice; open it in Twinfield and check the lines, VAT and totals.
3. Set it final; confirm `<financials>` comes back and reconciliation finds the open value.
4. Pay it in Twinfield; confirm the sweep marks the order paid.
5. Refund in Commerce; confirm the credit document lands and nets off.
6. Repeat 2–5 in journal mode.

Two smaller unknowns to settle in the same pass:

- **Address `field1`–`field6` carry no fixed meaning in the API.** Twinsies uses 1–3 for address
  lines and 4 for a VAT number, which is the convention Dutch installations use. Worth confirming
  against a real administration.
- **Credit invoice types.** Twinsies reuses the sales invoice type with negative amounts when no
  credit type is configured. Whether a given office prefers a dedicated credit type is a per-office
  question; both paths are implemented but only the negative-amount path is obvious.

## Launch prep (2026-10-04)

A security audit and a release review against Sager's and Exactly's launch prep. Fixed, with
regression checks where the suite can reach it (162 checks):

- The refund trigger read the **parent purchase** off `EVENT_AFTER_REFUND_TRANSACTION`. Now
  `Transactions::EVENT_AFTER_SAVE_TRANSACTION`, successful refunds only.
- `ProcessXmlString` was retried on a read timeout or a 500/504, which could post an invoice twice.
  Writes now resend only when nothing left (resolve/connect/TLS) or on 429/503; otherwise the
  document is failed as *may have posted* and parked out of automatic retries.
- `build()` wrote the debtor and articles before it could refuse; preview wrote them too. Writes
  now come after every refusal, and previews are dry runs.
- A missing open value read as zero — a Commerce payment nobody made.
- A token-validation timeout leaked the access token into `lastError`, the queue and the logs.
- Production could not connect at all (`allowAdminChanges` gate on the OAuth flow).
- Cluster URL pinned to https `*.twinfield.com`; literal client secrets refused; preview gated on
  push permission; `requireCpRequest()` everywhere; AJAX buttons returned redirects axios rejects;
  job TTRs and outage-only retries; log pruned on GC; fail-open wrappers on the panel hook and the
  sweep; settings page stops after one unreachable catalogue; env-var office kept as text; errors
  translated.

Known, deliberately deferred:

- `Reconcile` records the Commerce payment as the outstanding amount in the order currency and
  ignores `paymentRate` — fine for single-currency shops, wrong for a store that takes payment in
  another currency.
- The Documents screen needs only `twinsies-*` permissions; Sager also requires
  `commerce-manageOrders` and `canView($order)`.
- The non-office selects (invoice type, daybook, ledger accounts, VAT, bank) still fall back to the
  placeholder if a stored code is missing from the fetched list, so a save while a catalogue is
  short would blank it.
- `twinsies_documents.lastLogId` is never written.

## Still to do

- [ ] **Live Twinfield run (above)** — the only thing between this and being sellable. Add to it:
      a refund settled by gateway webhook, and Preview XML on a brand-new customer (no debtor or
      article should appear in Twinfield until the push).
- [ ] `git push` to a public `justinholtweb/craft-twinsies`, and push the `5.0.0` tag
- [ ] Packagist
- [ ] Register at `id.craftcms.com` and set the $149 price there — the price lives in the Craft
      Console, not in any repo, which is the one that silently disagrees with everything else
- [ ] Deploy justinholt.com, then on the server: `php craft index-assets/all`,
      `php craft pluginsite/page/import craft-twinsies`, `php craft pluginsite/docs/import`
