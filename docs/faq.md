---
title: FAQ
slug: faq
order: 50
summary: Licensing, what Twinsies does and does not do, and how it compares to the alternatives.
---

## Do I need the Twinfield invoicing module?

Only for **sales invoice** mode, where Twinfield produces a document it can print and send.

**Journal transaction** mode posts the bookkeeping only and works on every Twinfield subscription.
If you already send invoices from Craft, that is the mode you want.

## Does it need PHP's SOAP extension?

No. Twinfield's web services are SOAP, but there are two operations worth calling and both take
plain strings, so Twinsies builds the envelopes itself over the HTTP client Craft already ships.

That also means the request that failed can be shown to you verbatim, rather than disappearing
inside an extension.

## Where are my Twinfield credentials stored?

The client ID and secret are plugin settings, so put them in `.env` and reference them — plugin
settings are project config, and project config is committed.

The **grant** — the access and refresh tokens — is never in project config. It lives in Twinsies'
own database table, encrypted with your Craft security key. A Twinfield refresh token is valid for
roughly 25 years and grants full access to a company's books.

## Can it post to more than one Twinfield administration?

Not in this version. One site posts into one office.

## Will it post orders placed before I installed it?

Yes. **Post unposted orders** on the Documents screen, or `php craft twinsies/sync/pending`.

Post a few first and check them in Twinfield before doing the lot.

## What happens if Twinfield is down during checkout?

Nothing the customer sees. The trigger catches everything, and posting through the queue is on by
default — the order completes, and the document is parked for a retry.

Twinsies will never let an accounting system stop someone paying.

## Can I undo a posted document?

Not from Craft, and Twinsies does not pretend otherwise.

A concept invoice or provisional transaction can be deleted in Twinfield. A final one can only be
credited. **Forget this record** removes Twinsies' own copy so the order can be posted again — it
changes nothing in Twinfield.

This is why the defaults are concept and provisional.

## How are partial refunds handled?

The refunded amount is apportioned across the order's VAT codes in proportion to their value.

A partial refund cannot know which items came back, and that split is the only one that leaves the
VAT return correct without inventing facts about the return. A full refund mirrors the invoice
exactly.

## Does it handle EU VAT, reverse charge and exports?

It maps whatever Commerce charged to the Twinfield VAT code you choose, per rate or per tax
category, with a separate code for zero-rated lines.

It does **not** decide your VAT treatment for you. Commerce determines the rate; Twinsies makes sure
the right Twinfield code goes on the line so the return comes out right. Zero-rated exports and
reverse-charged intra-EU sales both look like zero, which is exactly why they get their own code
rather than falling through to the standard-rate default.

## Why did it refuse to post an order?

Because the lines did not add up to what the customer paid, by more than a rounding cent.

Twinsies would rather fail with a diagnostic than book an unexplained difference to a revenue
account. Books that reconcile and are wrong cost far more than a support ticket. The message names
the adjustment types it could not express.

## Is there a free edition?

No. Twinsies is a single paid edition at **$149**.

## Does the licence cover more than one site?

One licensed copy runs in one production environment. Development, staging and local copies do not
need their own licence.

## What Craft and Commerce versions does it support?

Craft CMS 5.3+, Craft Commerce 5.0+, PHP 8.2+. There is no Craft 4 version.

## Is it available in Dutch?

Yes — the whole control panel interface, in Twinfield's own vocabulary rather than a literal
translation: *administratie*, *dagboek*, *debiteur*, *grootboekrekening*, *afletteren*,
*creditfactuur*.

Set your Craft user's language to Dutch and the plugin follows.

## How does this compare to the generic connectors?

Middleware like Chift or Apideck can move data between Craft and Twinfield, on a monthly
subscription, with no knowledge of Commerce line items, adjusters or tax categories. You map fields
in their interface.

Twinsies is built for this one pair. It reads your actual chart of accounts to fill its dropdowns,
knows what an included tax adjustment means, reconciles every document against the order total
before sending, and shows you the exact XML.

## Is it affiliated with Wolters Kluwer?

No. Twinsies is an independent plugin. Twinfield is a product of Wolters Kluwer.

## Where do I get support?

[justin@justinholt.com](mailto:justin@justinholt.com). Send the log entry — the token is already
redacted, so it is safe to share.
