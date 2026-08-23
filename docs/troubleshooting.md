---
title: Troubleshooting
slug: troubleshooting
order: 40
summary: What Twinfield's error messages actually mean, and the settings that cause them.
---

Start with the **Log**. Twinfield reports rejections inside a 200 OK response, as attributes on the
tag it disliked, so the response body is the only place the reason appears. Twinsies keeps it.

## "Company ontbreekt in request header"

Twinfield answers in Dutch regardless of your language. This one means no office reached it.

Set an **office** on the settings screen. If the dropdown is empty, you are not connected, or the
connected Twinfield user cannot see any administrations.

## "Toegang geweigerd" / access denied

Usually one of three things:

- The office you chose is not one this Twinfield user can reach. `php craft twinsies/auth/offices`
  lists the ones that are.
- The grant was issued to a different Twinfield organisation. Reconnect.
- The Twinfield user lacks rights on the daybook or invoice type you configured.

## Every invoice looks permanently unpaid

Almost always because documents are being posted as **concept** or **provisional**. Those are not
financial transactions yet, so there is no open value to read.

Set **Status** to `final` (sales invoice mode) or **Destiny** to `final` (journal mode). Twinsies
warns about this on the settings screen rather than pretending to reconcile.

## "Unknown dimension" on the customer

The debtor does not exist in Twinfield.

- Switch on **Create and update Twinfield debtors**, or
- set a **guest debtor** that already exists, or
- check that the debtor code strategy is producing something Twinfield will accept — codes are at
  most 16 characters of `A-Z0-9`.

## The document totals more than the order

Twinsies refuses to post rather than book an unexplained difference. The message names the
adjustment types it could not express.

The usual cause is a custom Commerce adjuster. Every order-level adjustment has to be either `tax`,
`shipping` or `discount`, or mapped to an article and revenue account of its own.

Note that Twinsies deliberately never sends Commerce's tax as its own line — Twinfield derives the
VAT itself from each line's VAT code.

## Prices are 21% too high

The store prices inclusive of VAT and something is sending the gross figure as the ex-VAT price.
Twinsies handles this — when Commerce flags a tax adjustment as `included`, the tax is inside the
line subtotal and is taken back out.

If you are seeing it anyway, check that the Commerce tax rate really is marked as included, and
compare **Preview XML** against the order.

## The order posted twice

Twinfield has no idea a repost is a repost. Unless the site sends its own invoice numbers, posting
an order again creates a second document.

Twinsies will not do this on its own — the trigger records one document per order, guarded by a
unique index and a per-order lock. It happens when someone confirms the "post again" dialog.

Delete the duplicate in Twinfield.

## Nothing posts at all

Work down this list:

1. Is the plugin **connected**, and is an **office** set? Until both are true, Twinsies deliberately
   records nothing — a fresh install should not fill your queue with jobs that cannot succeed.
2. Is the **trigger** set to something other than "Only when I press the button"?
3. Is the order **completed**?
4. Is the queue running? With **Post through the queue** on, documents wait for a queue worker.

`php craft twinsies/sync/status` answers the first two in one line.

## "The stored tokens can no longer be decrypted"

Your `CRAFT_SECURITY_KEY` changed since the site connected — often because a database was copied
between environments without the matching key.

Disconnect and connect again.

## Twinfield times out on a large document

Twinfield's fair use guidance is 25 children to a parent element. Larger documents are accepted and
then time out on their side, which from Craft looks like a request that succeeded and posted
nothing.

Twinsies groups journal lines by account and VAT code for this reason. If you are hitting it with
sales invoices, an order with hundreds of distinct lines is the likely cause.

## A field Twinsies filled in is empty in Twinfield

Twinsies never sends an empty element. Twinfield reads an empty tag as "clear this field", which on
an update wipes data a bookkeeper set by hand — so a blank setting means the tag is omitted, not
sent empty.

If a field is empty in Twinfield, it was empty in Craft. Check the order, and check the phone and
VAT number field handles on the settings screen.

## Still stuck

The log entry has the exact XML that went out and came back. Send it to
[justin@justinholt.com](mailto:justin@justinholt.com) — with the access token already redacted, it
is safe to share.
