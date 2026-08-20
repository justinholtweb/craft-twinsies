# Twinsies — Craft CMS 5 Plugin

## Project Overview

Twinsies connects **Craft Commerce 5** to **Twinfield** (Wolters Kluwer's Dutch online accounting
platform). Orders become sales invoices *or* journal transactions, customers become `DEB`
dimensions, refunds become credit documents, and payment status is read back out of Twinfield.

Distributed as `justinholtweb/craft-twinsies`. **One paid edition, $149.** No edition gating
anywhere — do not add any.

## Why it exists

Nothing connects Craft Commerce to Twinfield today. The generic middleware (Chift, Apideck) charges
monthly, knows nothing about Commerce line items or adjusters, and hands the merchant a text box.

The hard parts are not the HTTP:

- **Twinfield is multi-cluster.** A call to the wrong cluster fails with an access error, not a
  redirect, and the right one is a claim on the *validated* access token.
- **Twinfield reports rejections inside a 200 OK**, as `result="0"` plus `msg`/`msgtype` attributes
  on whichever tag it disliked — and marks every ancestor failed too.
- **Two posting shapes, and a merchant needs one or the other.** The invoicing module is a separate
  Twinfield product; shops that send their own invoices need journal postings.

## Tech Stack

- **PHP 8.2+**, **Craft CMS 5.3+**, **Craft Commerce 5.0+**, Yii2, Twig
- No build step: no asset bundles, no JS beyond inline `{% js %}` blocks
- **No `ext-soap`.** There are two operations worth calling and both take scalars, so the envelopes
  are built by hand over Guzzle. Managed hosts that do not compile in `ext-soap` are common, and it
  means the request that failed can be shown to the merchant verbatim.

## Architecture

### Namespace & package

- Namespace: `justinholtweb\twinsies`
- Package: `justinholtweb/craft-twinsies`
- Handle: `twinsies`

### The three invariants

1. **`services\Documents::build()` is the only place a Commerce order becomes Twinfield XML.**
   The CP "Preview XML" button, `twinsies/sync/preview` and the real push all call it, so a preview
   is byte-identical to what Twinfield receives.
2. **`services\Sync::record()` is the only place a document row is created, and `push()` the only
   place one is sent.** The unique index on `twinsies_documents (orderId, sourceKey)` is what makes
   "has this been posted?" a fact; the **per-order mutex** in `push()` is what stops two workers
   both passing that check and creating two invoices no index could undo.
3. **`services\Api::request()` is the only place a request reaches Twinfield.** Token refresh,
   cluster resolution, the 401 retry, the bounded backoff and the log entry are written once.

### Data model

- `{{%twinsies_auth}}` — the single OAuth row. **Never in project config**: a Twinfield refresh
  token lives ~25 years and grants full access to a company's books. Tokens are encrypted *and
  base64-encoded* (see below).
- `{{%twinsies_documents}}` — every document Twinsies created. `sourceKey` is `invoice` or
  `refund:<commerce transaction hash>`, which is what makes two partial refunds two credit notes
  and a retried refund webhook one.
- `{{%twinsies_customers}}` — Craft identity → Twinfield `DEB` code, keyed `user:`/`email:`/`guest:`.
- `{{%twinsies_articles}}` — the mapping table. One `mapKey` string column rather than nullable
  `purchasableId`/`productTypeHandle` columns, because MySQL has no partial unique index and NULLs
  would let two rows claim the same thing.
- `{{%twinsies_log}}` — every round trip, access tokens redacted.

### Money and reconciliation

Line amounts come from Commerce's own adjustments rather than being recalculated. The build
finishes by reconciling against `order.totalPrice`: within `ROUNDING_TOLERANCE` (5c) it adds a
visible *Rounding* line, and **beyond it fails with a diagnostic naming the adjustment types it
could not express** rather than posting numbers nobody can explain.

Twinfield has no negative amounts on a transaction line — a credit is the same amount on the other
side. `helpers\Amounts::signed()` is the only place a side is chosen.

## Protocol notes (read from primary sources, not guessed)

Verified against the **live WSDLs** on a cluster host (`login.twinfield.com/webservices/*` is dead;
`accounting.twinfield.com/webservices/processxml.asmx?wsdl` is live), the official docs at
`developers.twinfield.com`, and the `php-twinfield/twinfield` reference client.

- **SOAP 1.1.** `SOAPAction: "http://www.twinfield.com/ProcessXmlString"` and
  `"http://www.twinfield.com/Search"`. Header element is `Header` in namespace
  `http://www.twinfield.com/`, carrying `AccessToken` and `CompanyCode`.
- **An empty office is not the same as no office.** Omitting `CompanyCode` entirely is the only way
  to list the offices a grant can reach; sending it empty returns "Company ontbreekt in request
  header", in Dutch, regardless of the caller's language.
- **OAuth**: authorize/token/validation all under `https://login.twinfield.com/auth/authentication/connect/`.
  Scopes `openid twf.user twf.organisation twf.organisationUser offline_access`. Credentials go in
  the **form body**, not a Basic header. Access tokens last an hour; refresh tokens ~25 years.
- **`twf.clusterUrl`** comes from POSTing the access token to `…/connect/accesstokenvalidation`.
- **Finder types** (from the reference client's own table): `OFF` offices, `VAT` VAT codes,
  `INV` invoice types, `TRS` daybooks, `DIM` dimensions (option `dimtype`: `BAS`/`PNL`/`DEB`),
  `ART` articles, `BNK` banks. Search field 0 = code or name, 1 = code, 2 = name. **It pages** —
  asking for everything in one call returns the first page and drops the rest.
- **`<read>` needs `<type>` first**; further down it returns an empty document rather than an error.
- **The open value element is misnamed.** The documentation says `<valueopen>`; live responses send
  `<openvalue>`. Read both, or every invoice looks permanently unpaid. It is on the **total** line.
- **A sales invoice only reports `<financials>` once it is `final`.** A concept invoice is not a
  financial transaction and genuinely has nothing to reconcile against.
- **Never emit Commerce's tax as its own line.** Twinfield derives VAT from each line's `vatcode`
  and books it itself; a tax line as well charges the VAT twice, and Twinfield accepts it because
  it is arithmetically consistent.
- **Fair use: keep a parent to 25 children.** Larger documents are accepted and then time out on
  Twinfield's side — which looks, from PHP, like a request that succeeded and posted nothing.

## Traps found while building this

- **`craft\base\Component::displayName()` is static**, and `Component` extends `yii\base\Model`, so
  `validate()` is taken too. Redeclaring either on a service is a **compile** error that fires when
  the class is autoloaded, nowhere near the call site. `tests/tools/collisions.php` re-checks the
  whole service layer for this.
- **`Security::encryptByKey()` returns raw binary.** Storing it in a `text` column on a utf8mb4
  connection fails with "Incorrect string value" — which MySQL reports as `SQLSTATE[22007] Invalid
  datetime format`. Base64 on the way in, base64 on the way out.
- **`DOMDocument::createTextNode()` escapes `<` and `&` but not `>`**, and a bare `]]>` is illegal
  in XML content. A merchant's invoice footer containing one produced a document Twinfield rejected
  as malformed. `helpers\Xml::toString()` escapes the sequence unconditionally; the SOAP envelope is
  built as a string with `htmlspecialchars(…, ENT_XML1)` for the same reason. CDATA is worse — `]]>`
  closes it early and silently truncates the document.
- **`?>` inside a `//` comment closes PHP mode.** Writing "a nested `<?xml ?>` declaration" in a
  one-line comment turned the rest of the file into inline HTML.
- **Writing DB-shaped values back onto a typed model is a TypeError.** `Sync::update()` was copying
  `Db::prepareDateForDb()` strings onto `?DateTime` properties. It re-reads the row instead — the
  model constructor is the only thing that knows how to turn a row back into a model.
- **A model must declare every column its query selects**, `uid` included, or Yii throws
  `UnknownPropertyException` on construction. Either declare them or select explicit columns.
- **Commerce 5 attaches a customer User to any order carrying an email**, so `getCustomer()` is
  usually non-null even for a "guest" checkout. Identity is user → email → order id.
- **`Variant::getProductTypeHandle()` exists** — walking to the product and its type costs an extra
  element query per line and throws for a variant whose product has gone.
- **`Address::getFieldValue()` throws on a handle the layout does not have**, so a phone or VAT
  field has to be found by asking the field layout first, not by catching.
- **A console request has no session**, so anything touching `Craft::$app->getSession()` fatals
  there. `Auth::session()` returns null instead, which also makes a stateless callback *refuse*
  rather than crash.
- **A fresh install must not start work.** With the trigger on "order complete", Twinsies recorded
  and queued a document for every completed order in the shared harness before it was configured at
  all. `Plugin::isConfigured()` (office set *and* a grant row) guards `Sync::shouldSync()` — and it
  is deliberately "never set up" rather than "cannot reach Twinfield right now", so a momentary
  outage still queues the order for retry.
- **Guzzle's history middleware holds its container by reference**, so a test helper returning a
  plain `array` hands back a copy taken before any request was made. Use an `ArrayObject`.
- **`preg_quote` is for patterns, not replacements.** Escaping a JWT with it corrupts every `.` in
  the token. The 401 retry splices the new token in with `substr`.
- **One project-config flush per console process** — set settings in memory on `$plugin->getSettings()`
  instead. (Family-wide; see `[[craft-sager-gotchas]]`.)

See also `[[craft-plugin-gotchas]]` in the shared memory for family-wide traps, and
`[[project_craft_sager]]` / `[[project_craft_lexies]]` / `[[project_craft_exactly]]` for the sibling
accounting connectors whose conventions this follows.

## Testing

No local PHP on this Mac. Everything runs inside the plugin-testing container:

```sh
cd ~/Sites/plugin-testing
ddev exec php /var/www/craft-twinsies/tests/integration/checks.php    # 155 checks
ddev exec php /var/www/craft-twinsies/tests/tools/collisions.php      # base-class method clashes
ddev exec php /var/www/craft-twinsies/tests/tools/dump-settings.php   # what project config stored
ddev exec bash -c 'find /var/www/craft-twinsies/src -name "*.php" -print0 | xargs -0 -n1 php -l'
```

Twinfield is **scripted, not stubbed**: a Guzzle `MockHandler` injected through `Api::setClient()`
and `Auth::setClient()`, so the envelope, the `result="0"` error attributes, the
401-refresh-and-retry, the 503 backoff and the idempotency index are exercised for real. What is
*not* exercised is Twinfield's own opinion of a document — nothing here can prove an invoice is
acceptable to a live administration. **That is the remaining risk, and it is the only one that
matters before release.**

The suite applies settings **in memory** (never project config), seeds a fake grant, and restores
every fixture, table and setting in a `finally`.

**Harness note:** `craft-penny` registers an `Elements::EVENT_BEFORE_SAVE_ELEMENT` handler typed
`ModelEvent` while Craft passes an `ElementEvent`, so **every element save in the harness fatals**
while it is enabled. `checks.php` detaches that handler in-process (never persisted). That is a real
bug in Penny, not in Twinsies. Fixtures also skip search indexing (`saveElement(…, false)` for the
index argument), which is the only part of those saves that deadlocks against the rest of the
shared harness.

## Coding conventions

- `Craft::t('twinsies', '…')` for user-facing strings; `src/translations/en/twinsies.php` lists them.
  A `nl` file is the first translation worth having — this plugin exists for a Dutch product.
- Business logic in services; controllers stay thin
- Never nest a `<form>` in a CP template — post secondary actions with `Craft.sendActionRequest`
- Never mark plugin settings `required`
- Anything on the order-completion path fails **open**: Twinfield being down must never be able to
  stop a customer paying
