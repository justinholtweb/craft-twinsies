---
title: Configuration
slug: configuration
order: 20
summary: Choosing a posting mode, mapping articles, accounts and VAT, and deciding when orders go across.
---

Work through the settings screen top to bottom. The order below is the one that saves the most
pain, and the last step is the one that catches mistakes before Twinfield sees them.

## 1. Choose a mode

Twinsies can post an order two ways, and which one you want depends on what your Twinfield
subscription includes.

**Sales invoice** produces a document Twinfield can print, email and chase. It needs the Twinfield
invoicing module.

**Journal transaction** posts the bookkeeping only: the debtor is debited, revenue is credited, and
Twinfield derives the VAT. It works on every Twinfield subscription, and it is what you want if you
already send your own invoices from Craft.

You can switch modes later, but documents already posted stay as they were.

## 2. Articles and ledger accounts

At minimum, set a **default article** and a **default revenue account**. Both are dropdowns fed
from your actual Twinfield administration, so you are choosing from your own chart of accounts
rather than typing a code and hoping.

A mistyped ledger account is not rejected by Twinfield. It is *created*. That is why these are
lists.

In journal mode you also need a **debtor account** — the balance sheet account for trade debtors,
usually something like `1300`. The total line books there.

For anything more specific than a default, use the **Mapping** screen (see below).

## 3. VAT

Twinsies has to put a Twinfield VAT code on every line, and it can decide that two ways.

**By the rate charged** is the default and usually the right one. Map each percentage you charge to
a Twinfield code:

| Rate % | Twinfield VAT code |
| --- | --- |
| 21 | VH |
| 9 | VL |

**By Commerce tax category** matches on the category handle instead. It is simpler, and it goes
wrong the moment one tax category covers several zones — a Dutch shop shipping to Belgium charges
21% under one category and 0% under another, and both would book to the same code.

Set a **zero-rate VAT code** as well. Lines that carry no tax at all are not the same thing as
lines that failed to match: a zero-rated export and a reverse-charged intra-EU sale both look like
zero here, and both need a real code.

Never add a Commerce tax adjustment as its own line and expect Twinfield to leave it alone —
Twinsies deliberately never sends one, because Twinfield derives the VAT itself from the line's VAT
code. Sending both charges the VAT twice, and Twinfield accepts it, because it is arithmetically
consistent.

## 4. Customers

Twinfield will not book an invoice to a customer that does not exist, and it will not create one
for you. Leave **Create and update Twinfield debtors** on and Twinsies creates the `DEB` dimension
before it posts.

**Debtor code** decides how those debtors are numbered:

- **Let Twinfield number them** — the default, and the safest. A code derived from Craft collides
  the moment your bookkeeper also enters customers by hand.
- **From the Craft user ID** or **from the email address** — derived, prefixed if you like.
- **From a template** — an object template rendered against the order.

If you run a busy B2C shop, consider setting a **guest debtor** instead: one existing Twinfield
debtor that every guest order books to. Thousands of one-off dimensions in a chart of accounts is
rarely what a bookkeeper wants.

**Update debtors that already exist** is off by default on purpose. A bookkeeper who corrected a
customer's address in Twinfield does not expect the next web order to put the old one back.

## 5. Mapping

The **Mapping** screen answers two questions for each kind of line: which Twinfield article it
appears as, and which revenue account it books to.

Rows are a fallback chain, most specific first:

1. a specific purchasable (variant)
2. its product type
3. **Everything else**
4. the defaults on the settings screen

Shipping and discounts have their own rows, so shipping revenue can land on its own account.

Leaving a row entirely blank deletes it and falls back to the next one.

## 6. Trigger

Leave this on **Only when I press the button** until a preview looks right.

When you are ready, choose:

- **When the order is completed** — every completed order posts.
- **When the order reaches a status** — pick the statuses. Useful if you only invoice what you have
  actually shipped.

**Post through the queue** should stay on. Order completion happens inside the customer's payment
request, and a slow Twinfield must never be able to hold up a checkout.

## 7. Concept first

Leave documents on **concept** (sales invoice mode) or **provisional** (journal mode) until you are
happy with what is coming out. A final invoice cannot be deleted in Twinfield, only credited.

## Check before you post

Open a completed order and press **Preview XML**. That runs the same builder the real push does, so
what you see is exactly what Twinfield would receive — not a re-render that might differ.

Or from the command line:

```sh
php craft twinsies/sync/preview <order reference>
```

## Payments and refunds

Both are optional and both are covered in [Usage](usage).
