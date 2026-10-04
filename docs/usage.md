---
title: Usage
slug: usage
order: 30
summary: Posting orders, reading payments back, crediting refunds, and the console commands.
---

## Posting an order

With a trigger configured, a completed order posts on its own. Everything else is a button.

On Commerce's order edit screen, Twinsies adds a panel showing the document's status, its Twinfield
reference, and two buttons: **Preview XML** and **Post to Twinfield**.

The **Documents** screen lists everything Twinsies has posted or tried to post, filtered by status:

| Status | Meaning |
| --- | --- |
| Pending | Recorded, not yet sent |
| Queued | Handed to the queue |
| Posted | Twinfield accepted it |
| Paid | Twinfield reports it settled |
| Failed | Twinfield rejected it, or it could not be built |
| Skipped | Deliberately not posted |

A failed document keeps the reason Twinfield gave, down to the field it objected to.

### Who can see what

| Permission | Allows |
| --- | --- |
| **View Twinfield documents** | The Documents screen and the order panel. Also needs Commerce's **Manage orders**, and only shows orders the user can view in Commerce. |
| ↳ **Post documents to Twinfield** | Preview XML, Post, Post again, Retry failed, Post unposted orders |
| **Manage article and ledger mapping** | The Mapping screen |
| **View the Twinfield connection log** | The Log screen. Request and response bodies include customer names and addresses. |

Settings and connecting to Twinfield are for admins only.

### Posting a backlog

**Post unposted orders** on the Documents screen queues completed orders that have no document yet.
From the console:

```sh
php craft twinsies/sync/pending --limit=50
php craft twinsies/sync/retry              # documents that failed, with attempts left
```

### Posting again

Be careful here. **Twinfield has no idea a repost is a repost.** Unless you have switched on
"Send Craft's order reference as the invoice number", posting the same order again creates a
*second* document rather than replacing the first. The control panel makes you confirm; the trigger
never does it on its own.

If a document is wrong and has not been booked yet, delete it in Twinfield and use **Forget this
record** in Twinsies, then post again. Forgetting only removes Twinsies' own copy — it cannot
change anything in Twinfield, and pretending otherwise would be worse than not offering it.

## Reading payments back

Twinfield is where a bank statement gets matched against an invoice. For bank transfer and
on-account orders, Twinfield knows an order is paid before Craft does.

Switch on **Read payment status back from Twinfield** and Twinsies polls the open value of each
document it has posted. When the open value reaches zero, it can:

- move the order to a **paid order status**, and
- optionally **record a Commerce payment**, so the order counts as paid rather than merely
  re-statused.

That second option is off by default. Commerce's paid state is normally owned by a payment gateway,
and writing a payment no gateway took is a claim about money worth making deliberately.

**Reconciliation needs booked documents.** A concept invoice or a provisional transaction is not a
financial transaction yet, so it has no open value and no match status. If reconciliation seems to
do nothing, this is almost always why — and Twinsies will say so rather than leaving every invoice
looking permanently unpaid.

Twinsies queues its own sweep on an interval, so a cron entry is an optimisation rather than a
requirement:

```sh
php craft twinsies/reconcile/run
php craft twinsies/reconcile/open     # what is still waiting
```

## Refunds

With **credit notes** switched on, a Commerce refund posts a credit document automatically.

A full refund mirrors the invoice exactly, which keeps the VAT per line correct. A **partial**
refund cannot know which items came back, so the amount is apportioned across the order's VAT codes
in proportion to their value — the only split that leaves the VAT return right without inventing
facts about the return.

Two partial refunds produce two credit notes; a retried refund webhook produces one.

If your administration has a dedicated credit invoice type or credit daybook, set it. Otherwise
Twinsies reuses the sales type with negative amounts, which is what an administration without one
needs.

## When a document will not build

Twinsies reconciles the lines it built against the order's total before it sends anything. A
difference of a cent or two is booked to a visible **Rounding** line. Anything larger fails the
build and names the adjustment types it could not express.

That refusal is deliberate. A connector that quietly books an unexplained difference to a revenue
account produces books that reconcile and are wrong, and nobody finds out until an accountant does.

The usual cause is a custom Commerce adjuster — a tip, a surcharge, a deposit — that Twinsies has no
line for. Map it to an article and a revenue account, or exclude it.

## The log

Every request and response is kept, XML included, with access tokens redacted.

This matters more than it sounds. Twinfield reports rejections *inside* a 200 OK, as attributes on
whichever tag it disliked, so the response body is the only place the reason ever appears.

Filter by level or action, and prune on a retention you choose.

## Twig

```twig
{% set document = craft.twinsies.document(order) %}

{% if document and document.isSent() %}
  <p>Invoice {{ document.getReference() }}</p>

  {% if craft.twinsies.isPaid(order) %}
    <p>Paid.</p>
  {% endif %}
{% endif %}
```

| Method | Returns |
| --- | --- |
| `craft.twinsies.document(order)` | the invoice document, or `null` |
| `craft.twinsies.documents(order)` | the invoice and any credit notes |
| `craft.twinsies.isPaid(order)` | whether Twinfield reports it settled |
| `craft.twinsies.reference(order)` | `FACTUUR 2026001`, once posted |
| `craft.twinsies.isConnected()` | whether the site is connected |

Everything on the front end is read-only. Nothing there can post to Twinfield.

## Console reference

```sh
php craft twinsies/auth/status              # connection and cluster
php craft twinsies/auth/offices             # administrations this user can reach
php craft twinsies/auth/test                # make a real request

php craft twinsies/sync/status              # a summary of what has been posted
php craft twinsies/sync/preview <ref>       # print the XML an order would post
php craft twinsies/sync/order <ref>         # post one order
php craft twinsies/sync/pending --limit=50  # post completed orders that have none
php craft twinsies/sync/retry               # retry failures

php craft twinsies/reconcile/run            # check for payments
php craft twinsies/reconcile/open           # list open documents

php craft twinsies/log/prune                # honour the retention setting
php craft twinsies/log/clear
```

Orders can be named by reference, order number or element ID — whichever you have to hand.
