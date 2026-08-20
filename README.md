# Twinsies

**Twinfield integration for Craft Commerce 5.**

Twinsies posts completed Commerce orders into [Twinfield](https://www.wolterskluwer.com/nl-nl/solutions/twinfield-online-boekhouden) — as sales invoices, or as journal transactions — keeps customers and articles in step, reads payment status back, and credits refunds.

Requires **PHP 8.2+**, **Craft CMS 5.3+** and **Craft Commerce 5.0+**. No `ext-soap`, no build step, no runtime dependencies beyond Craft's own.

---

## Two ways to post an order

Twinfield's invoicing module is a separate product, and plenty of Dutch shops do not have it — they send their own invoices and only need the bookkeeping. So Twinsies does both, and you pick per site.

**Sales invoice** produces a document Twinfield can print, email and chase.

```xml
<salesinvoice>
  <header>
    <office>001</office>
    <invoicetype>FACTUUR</invoicetype>
    <customer>1000</customer>
    <invoicedate>20260820</invoicedate>
    <duedate>20260903</duedate>
    <currency>EUR</currency>
    <status>concept</status>
    <paymentmethod>bank</paymentmethod>
  </header>
  <lines>
    <line id="1">
      <article>WIDGET</article>
      <quantity>2</quantity>
      <unitspriceexcl>49.50</unitspriceexcl>
      <vatcode>VH</vatcode>
      <dim1>8000</dim1>
    </line>
  </lines>
</salesinvoice>
```

**Journal transaction** posts the accounting only: the debtor is debited, revenue is credited, Twinfield derives the VAT.

```xml
<transaction destiny="temporary" autobalancevat="true">
  <header><office>001</office><code>VRK</code><date>20260820</date>…</header>
  <lines>
    <line type="detail" id="1"><dim1>8000</dim1><debitcredit>credit</debitcredit><value>99.00</value><vatcode>VH</vatcode></line>
    <line type="total"  id="2"><dim1>1300</dim1><dim2>1000</dim2><debitcredit>debit</debitcredit><value>119.79</value></line>
  </lines>
</transaction>
```

## What else it does

- **Customers become Twinfield debtors.** The `DEB` dimension is created or updated *before* the document is posted, so an order never fails on a missing debtor. Four code strategies, or one catch-all debtor for guest checkouts.
- **Products map to articles and ledger accounts.** Per purchasable, per product type, and separately for shipping and discounts, with a fallback chain and a mapping screen driven by your real chart of accounts.
- **VAT maps by the rate actually charged**, or by Commerce tax category. Zero-rated lines get their own code, because a zero-rated export and a reverse-charged intra-EU sale are not the same thing to a VAT return.
- **Payments are read back.** Twinfield is where a bank statement gets matched to an invoice, so for bank transfer and on-account orders Twinfield knows an order is paid before Craft does. Twinsies polls the open value and can move the order's status, or record a Commerce payment.
- **Refunds become credit documents.** A partial refund is apportioned across the order's VAT codes in proportion to their value.
- **Every round trip is logged**, request and response XML included, access tokens redacted. Twinfield reports rejections *inside* a 200 OK, as attributes on whichever tag it disliked, so the response body is the only place the reason ever appears.

## Install

```sh
composer require justinholtweb/craft-twinsies
php craft plugin/install twinsies
```

## Connect

1. Register an app in the Twinfield developer portal.
2. Copy the **Redirect URI** shown on Twinsies' settings screen into that registration, character for character — Twinfield matches it exactly.
3. Paste the client ID and secret into the settings screen. Put the secret in `.env` and reference it (`$TWINFIELD_CLIENT_SECRET`): plugin settings are project config, and project config is committed.
4. Press **Connect to Twinfield** and authorise.
5. Pick an office, then press **Test connection**.

The grant is stored in `{{%twinsies_auth}}`, encrypted with Craft's security key — never in project config. A Twinfield refresh token is good for about 25 years and grants full access to a company's accounts.

## Set it up

Work through the settings screen top to bottom. The order that saves pain:

1. **Mode** — sales invoice or journal transaction.
2. **Articles and accounts** — a default article and a default revenue account, at minimum. Both are dropdowns fed from your real Twinfield administration.
3. **VAT** — map each rate you charge to a Twinfield VAT code, and set a zero-rate code.
4. **Customers** — leave debtor numbering to Twinfield unless you have a reason not to.
5. **Trigger** — leave it on manual until a preview looks right.

Then open a completed order, press **Preview XML**, and read what comes out. That preview runs the same builder the push does, so it is exactly what Twinfield would receive.

Leave documents on **concept** (or **provisional**) until you are happy. A final invoice cannot be deleted in Twinfield, only credited.

## Console

```sh
php craft twinsies/auth/status              # is this site connected, and to which cluster
php craft twinsies/auth/offices             # what this Twinfield user can reach
php craft twinsies/auth/test                # make a real request and report what came back

php craft twinsies/sync/status              # a summary of what has been posted
php craft twinsies/sync/preview <reference> # print the XML an order would post
php craft twinsies/sync/order <reference>   # post one order
php craft twinsies/sync/pending --limit=50  # post completed orders that have none
php craft twinsies/sync/retry               # retry documents that failed

php craft twinsies/reconcile/run            # ask Twinfield which documents have been paid
php craft twinsies/reconcile/open           # list documents still waiting

php craft twinsies/log/prune                # honour the retention setting
```

Reconciliation queues itself on an interval, so a cron entry is an optimisation rather than a requirement.

## Twig

```twig
{% set document = craft.twinsies.document(order) %}

{% if document and document.isSent() %}
  <p>Invoice {{ document.getReference() }}</p>
  {% if craft.twinsies.isPaid(order) %}<p>Paid.</p>{% endif %}
{% endif %}
```

Everything on the front end is read-only. Nothing there can post to Twinfield.

## Things worth knowing

**Twinfield has no idea a repost is a repost.** Unless the site sends its own invoice numbers, posting an order a second time creates a *second* document rather than replacing the first. The control panel makes you confirm; the trigger never does it.

**Reconciliation needs booked documents.** A concept invoice or a provisional transaction is not a financial transaction, so it has no open value and no match status. Twinsies says so rather than looking permanently unpaid.

**A document that does not add up is not posted.** If the lines do not reconcile against `order.totalPrice`, a difference within a cent or two is booked to a visible *Rounding* line and anything larger fails the build, naming the adjustment types Twinsies could not express. Books that reconcile and are wrong cost more than a support ticket.

**Nothing here can stop a checkout.** The trigger catches everything, and pushing through the queue is on by default.

## Support

[justin@justinholt.com](mailto:justin@justinholt.com)

Twinsies is not affiliated with Wolters Kluwer or Twinfield.
