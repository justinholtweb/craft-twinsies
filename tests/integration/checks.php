<?php
/**
 * Twinsies integration checks.
 *
 * Run inside the plugin-testing container, from the site root:
 *
 *     ddev exec php /var/www/craft-twinsies/tests/integration/checks.php
 *
 * Twinfield is *scripted*, not stubbed: a Guzzle `MockHandler` is injected through
 * `Api::setClient()` and `Auth::setClient()`, so the SOAP envelope, Twinfield's `result="0"` error
 * attributes, the 401-refresh-and-retry and the retry backoff are exercised for real. What is not
 * exercised is Twinfield's own opinion of a document — nothing here can prove an invoice is
 * acceptable to a live administration.
 *
 * Idempotent and self-cleaning: fixture products, orders, documents, mappings, log rows, the
 * seeded OAuth grant and every setting it overwrites are restored in a `finally`, pass or fail.
 */

$root = getcwd();
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\commerce\elements\Order;
use craft\commerce\elements\Product;
use craft\commerce\elements\Variant;
use craft\commerce\models\OrderAdjustment;
use craft\commerce\Plugin as Commerce;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use justinholtweb\twinsies\db\Table;
use justinholtweb\twinsies\errors\TwinfieldException;
use justinholtweb\twinsies\helpers\Amounts;
use justinholtweb\twinsies\helpers\Dates;
use justinholtweb\twinsies\helpers\Xml;
use justinholtweb\twinsies\models\ArticleMap;
use justinholtweb\twinsies\models\Document;
use justinholtweb\twinsies\models\Settings;
use justinholtweb\twinsies\Plugin;
use justinholtweb\twinsies\services\Api;
use justinholtweb\twinsies\services\Auth;
use justinholtweb\twinsies\services\Mapping;

$passed = 0;
$failed = 0;

function check(string $label, callable $test): void
{
    global $passed, $failed;

    try {
        $result = $test();

        if ($result === true) {
            $passed++;
            echo "  ✓ $label\n";
            return;
        }

        $failed++;
        echo "  ✗ $label\n    " . (is_string($result) ? $result : 'returned ' . var_export($result, true)) . "\n";
    } catch (Throwable $e) {
        $failed++;
        echo "  ✗ $label\n    " . get_class($e) . ': ' . $e->getMessage() . "\n    " . $e->getFile() . ':' . $e->getLine() . "\n";
    }
}

function section(string $title): void
{
    echo "\n$title\n";
}

$plugin = Plugin::getInstance();
$commerce = Commerce::getInstance();
$storeId = $commerce->getStores()->getPrimaryStore()->id;

$createdProducts = [];
$createdOrders = [];
$originalSettings = $plugin->getSettings()->toArray();

// `craft-penny` (a sibling plugin in this shared harness) registers an
// Elements::EVENT_BEFORE_SAVE_ELEMENT handler typed `ModelEvent`, but Craft passes an
// `ElementEvent` for that event — so saving *any* element fatals while it is enabled. Nothing to
// do with Twinsies; detached in-process here (never persisted) so fixtures can be created.
if (Craft::$app->getPlugins()->isPluginEnabled('penny')) {
    yii\base\Event::off(craft\services\Elements::class, craft\services\Elements::EVENT_BEFORE_SAVE_ELEMENT);
    echo "  ! detached craft-penny's broken beforeSaveElement handler for this run\n";
}

/**
 * Settings are applied **in memory**, never through project config.
 *
 * A console process gets exactly one project-config flush: the flush writes `config/project/*.yaml`
 * with a fresh `dateModified`, and the next write in the same process throws
 * `StaleResourceException` against the file it just wrote.
 *
 * @param array<string, mixed> $values
 */
function settings(array $values = []): Settings
{
    global $plugin;

    $settings = $plugin->getSettings();

    foreach ($values as $key => $value) {
        $settings->$key = $value;
    }

    return $settings;
}

/**
 * Rebuild a service, dropping whatever it memoised.
 */
function resetService(string $name, string $class): void
{
    global $plugin;

    $plugin->set($name, ['class' => $class]);
}

/**
 * Wrap a Twinfield document in the SOAP envelope the service actually answers with.
 */
function soapResponse(string $innerXml, int $status = 200): GuzzleResponse
{
    $body = '<?xml version="1.0" encoding="utf-8"?>'
        . '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">'
        . '<soap:Body><ProcessXmlStringResponse xmlns="http://www.twinfield.com/">'
        . '<ProcessXmlStringResult>' . htmlspecialchars($innerXml, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</ProcessXmlStringResult>'
        . '</ProcessXmlStringResponse></soap:Body></soap:Envelope>';

    return new GuzzleResponse($status, ['Content-Type' => 'text/xml; charset=utf-8'], $body);
}

/**
 * Script Twinfield's answers, and collect the requests that were actually sent.
 *
 * The container is an `ArrayObject`, not an array. Guzzle's history middleware holds it by
 * reference, and a plain array returned from here would be a *copy* taken before any request was
 * made — so every assertion about what was sent would quietly see nothing.
 *
 * @param array<int, GuzzleResponse|Throwable> $responses
 * @return ArrayObject<int, array<string, mixed>> filled as requests are made
 */
function scriptTwinfield(array $responses): ArrayObject
{
    global $plugin;

    $history = new ArrayObject();
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($history));

    $plugin->getApi()->setClient(new Client(['handler' => $stack]));

    return $history;
}

/**
 * The body of the nth request the plugin sent.
 */
function sentBody(ArrayObject $history, int $index = 0): string
{
    if (!isset($history[$index])) {
        throw new RuntimeException("No request #{$index} was sent (" . count($history) . ' total).');
    }

    return (string)$history[$index]['request']->getBody();
}

/**
 * The Twinfield document out of a SOAP envelope Twinsies sent.
 */
function sentDocument(ArrayObject $history, int $index = 0): DOMDocument
{
    $envelope = Xml::parse(sentBody($history, $index));
    $request = $envelope->getElementsByTagNameNS('http://www.twinfield.com/', 'xmlRequest')->item(0);

    if ($request === null) {
        throw new RuntimeException('The request carried no xmlRequest element.');
    }

    return Xml::parse($request->textContent);
}

/**
 * Pretend the site is connected, without touching Twinfield's identity server.
 */
function seedConnection(?DateTime $expiresAt = null): void
{
    global $plugin;

    $security = Craft::$app->getSecurity();

    Craft::$app->getDb()->createCommand()->delete(Table::AUTH)->execute();
    Craft::$app->getDb()->createCommand()->insert(Table::AUTH, [
        'clientIdHash' => hash('sha256', $plugin->getSettings()->getClientId()),
        'accessToken' => base64_encode($security->encryptByKey('fixture-access-token')),
        'refreshToken' => base64_encode($security->encryptByKey('fixture-refresh-token')),
        'expiresAt' => Db::prepareDateForDb($expiresAt ?? (new DateTime())->modify('+1 hour')),
        'clusterUrl' => 'https://api.accounting.twinfield.com',
        'scope' => Auth::SCOPES,
        'twinfieldUser' => 'fixture@example.com',
        'dateCreated' => Db::prepareDateForDb(new DateTime()),
        'dateUpdated' => Db::prepareDateForDb(new DateTime()),
        'uid' => StringHelper::UUID(),
    ])->execute();

    resetService('auth', Auth::class);
}

function makeProduct(string $sku, float $price): Product
{
    global $createdProducts;

    $type = Commerce::getInstance()->getProductTypes()->getAllProductTypes()[0];

    $product = new Product();
    $product->typeId = $type->id;
    $product->title = "Twinsies fixture $sku";
    $product->enabled = true;

    $variant = new Variant();
    $variant->sku = $sku;
    $variant->basePrice = $price;
    $variant->isDefault = true;

    $product->setVariants([$variant]);

    if (!Craft::$app->getElements()->saveElement($product, true, true, false)) {
        throw new RuntimeException('Could not save fixture product: ' . json_encode($product->getErrors()));
    }

    $createdProducts[] = $product;

    return $product;
}

/**
 * @param array<int, array{variant: Variant, qty: int}> $lines
 */
function makeOrder(array $lines, bool $complete = true, array $adjustments = []): Order
{
    global $createdOrders, $storeId;

    $order = new Order();
    $order->storeId = $storeId;
    $order->orderSiteId = Craft::$app->getSites()->getPrimarySite()->id;
    $order->number = Commerce::getInstance()->getCarts()->generateCartNumber();
    $order->setEmail('twinsies-fixture@example.com');

    if (!Craft::$app->getElements()->saveElement($order, false, true, false)) {
        throw new RuntimeException('Could not save order: ' . json_encode($order->getErrors()));
    }

    $createdOrders[] = $order;

    $lineItems = [];

    foreach ($lines as $line) {
        $lineItems[] = Commerce::getInstance()->getLineItems()->createLineItem(
            $order,
            $line['variant']->id,
            [],
            $line['qty']
        );
    }

    $order->setLineItems($lineItems);

    // Commerce insists an address element is owned by its order, so the attributes go in as an
    // array and Commerce builds the owned element itself.
    $address = [
        'fullName' => 'Dana Fixture',
        'organization' => 'Fixture BV',
        'addressLine1' => 'Keizersgracht 1',
        'locality' => 'Amsterdam',
        'postalCode' => '1015 CJ',
        'countryCode' => 'NL',
    ];
    $order->setShippingAddress($address);
    $order->setBillingAddress($address);

    if (!Craft::$app->getElements()->saveElement($order, false, true, false)) {
        throw new RuntimeException('Could not save order lines: ' . json_encode($order->getErrors()));
    }

    if ($adjustments) {
        $built = [];

        foreach ($adjustments as $spec) {
            $adjustment = new OrderAdjustment();
            $adjustment->setOrder($order);
            $adjustment->type = $spec['type'];
            $adjustment->name = $spec['name'] ?? ucfirst($spec['type']);
            $adjustment->description = $spec['description'] ?? '';
            $adjustment->amount = $spec['amount'];
            $adjustment->included = $spec['included'] ?? false;
            $adjustment->sourceSnapshot = $spec['snapshot'] ?? [];

            if (!empty($spec['lineItemIndex'])) {
                $adjustment->setLineItem($order->getLineItems()[$spec['lineItemIndex'] - 1]);
            }

            $built[] = $adjustment;
        }

        $order->setAdjustments($built);
    }

    if ($complete) {
        $order->markAsComplete();
    }

    return $order;
}

try {
    // Nothing should post while fixtures are being built.
    settings([
        'trigger' => Settings::TRIGGER_MANUAL,
        'clientId' => 'fixture-client',
        'clientSecret' => 'fixture-secret',
        'office' => '001',
        'mode' => Settings::MODE_SALES_INVOICE,
        'invoiceType' => 'FACTUUR',
        'invoiceStatus' => Settings::INVOICE_STATUS_CONCEPT,
        'daybook' => 'VRK',
        'destiny' => Settings::DESTINY_TEMPORARY,
        'debtorGl' => '1300',
        'defaultRevenueGl' => '8000',
        'defaultArticle' => 'WEBSHOP',
        'shippingArticle' => 'VERZEND',
        'shippingGl' => '8100',
        'discountArticle' => 'KORTING',
        'discountGl' => '8200',
        'defaultVatCode' => 'VH',
        'zeroVatCode' => 'VN',
        'vatRateMap' => [['rate' => '21', 'vatCode' => 'VH'], ['rate' => '9', 'vatCode' => 'VL']],
        'vatCategoryMap' => [['category' => 'general', 'vatCode' => 'VH']],
        'vatSource' => Settings::VAT_BY_RATE,
        'syncCustomers' => false,
        'guestCustomerCode' => '1000',
        'autoCreateArticles' => false,
        'queuePush' => false,
        'dueDays' => 14,
        'loggingEnabled' => true,
        'logPayloads' => true,
        'reconcileEnabled' => false,
        'creditNotesEnabled' => true,
        'sendPeriod' => true,
        'maxAttempts' => 2,
    ]);

    seedConnection();

    // ---------------------------------------------------------------------
    section('Amounts');

    check('money is always two decimals with a dot', fn() => Amounts::money(1234.5) === '1234.50' ?: Amounts::money(1234.5));
    check('money never groups thousands', fn() => !str_contains(Amounts::money(1234567.891), ',') && Amounts::money(1234567.891) === '1234567.89');
    check('money rounds to the cent', fn() => Amounts::money(0.005) === '0.01' ?: Amounts::money(0.005));
    check('a precise price keeps sub-cent detail', fn() => Amounts::precise(0.335) === '0.335' ?: Amounts::precise(0.335));
    check('a precise price still shows two decimals for round numbers', fn() => Amounts::precise(49.5) === '49.50' ?: Amounts::precise(49.5));
    check('a precise whole number gains decimals', fn() => Amounts::precise(12) === '12.00' ?: Amounts::precise(12));

    check('a negative amount becomes the opposite side, never a negative value', function() {
        [$value, $side] = Amounts::signed(-25.5, Amounts::CREDIT);

        return ($value === '25.50' && $side === Amounts::DEBIT) ?: "$value / $side";
    });

    check('a positive amount keeps the side it was given', function() {
        [$value, $side] = Amounts::signed(25.5, Amounts::CREDIT);

        return ($value === '25.50' && $side === Amounts::CREDIT) ?: "$value / $side";
    });

    check('equality tolerates float error', fn() => Amounts::equal(0.1 + 0.2, 0.3));
    check('equality still rejects a cent', fn() => !Amounts::equal(10.00, 10.01));

    // ---------------------------------------------------------------------
    section('Dates');

    check('a date is yyyyMMdd', fn() => Dates::date(new DateTime('2026-03-09 12:00:00')) === '20260309');
    check('a period is yyyy/PP', fn() => Dates::period(new DateTime('2026-03-09 12:00:00')) === '2026/03');
    check('due days move the date', fn() => Dates::date(Dates::addDays(new DateTime('2026-03-09'), 14)) === '20260323');
    check('zero due days leave the date alone', fn() => Dates::date(Dates::addDays(new DateTime('2026-03-09'), 0)) === '20260309');
    check('a Twinfield date parses back', fn() => Dates::parse('20260309')?->format('Y-m-d') === '2026-03-09');
    check('a non-date parses to null', fn() => Dates::parse('09-03-2026') === null && Dates::parse('') === null);

    check('a date is formatted in the site time zone, not UTC', function() {
        // The trap this guards: an order placed just after midnight Amsterdam is the previous day
        // in UTC, and formatting it in UTC books it into the wrong VAT period.
        $utcMidnightish = new DateTime('2026-03-31 23:30:00', new DateTimeZone('UTC'));
        $expected = (clone $utcMidnightish)->setTimezone(new DateTimeZone(Craft::$app->getTimeZone()))->format('Ymd');

        return Dates::date($utcMidnightish) === $expected ?: Dates::date($utcMidnightish) . ' vs ' . $expected;
    });

    // ---------------------------------------------------------------------
    section('XML');

    check('a well-formed response parses', fn() => Xml::parse('<salesinvoice result="1"/>')->documentElement->nodeName === 'salesinvoice');
    check('an empty body is refused', function() {
        try {
            Xml::parse('   ');
            return 'no exception';
        } catch (RuntimeException) {
            return true;
        }
    });

    check('result="1" is success', fn() => Xml::succeeded(Xml::parse('<salesinvoice result="1"><header result="1"/></salesinvoice>')));
    check('result="0" is failure', fn() => !Xml::succeeded(Xml::parse('<salesinvoice result="0"><header result="0"/></salesinvoice>')));
    check('a read response with no result attribute counts as success', fn() => Xml::succeeded(Xml::parse('<dimension><code>1000</code></dimension>')));
    check('a nested failure fails the document even without a root result', fn() => !Xml::succeeded(Xml::parse('<dimension><code result="0" msg="bad">X</code></dimension>')));

    check('the innermost message is reported first', function() {
        $doc = Xml::parse('<salesinvoice result="0" msg="Invoice not created" msgtype="error"><header result="0"><duedate result="0" msg="Invalid date" msgtype="error">x</duedate></header></salesinvoice>');
        $errors = Xml::collectErrors($doc);

        return ($errors[0]['field'] === 'duedate' && $errors[0]['message'] === 'Invalid date') ?: json_encode($errors);
    });

    check('warnings are separated from errors', function() {
        $doc = Xml::parse('<salesinvoice result="0"><header><bank msg="Missing bank" msgtype="warning">x</bank><customer msg="Unknown" msgtype="error">y</customer></header></salesinvoice>');

        return count(Xml::collectMessages($doc)) === 2 && count(Xml::collectErrors($doc)) === 1;
    });

    check('the summary names the field that failed', function() {
        $doc = Xml::parse('<salesinvoice result="0"><header><customer result="0" msg="Unknown dimension" msgtype="error">9999</customer></header></salesinvoice>');

        return str_contains(Xml::summariseErrors($doc), 'customer: Unknown dimension') ?: Xml::summariseErrors($doc);
    });

    check('a rejection with no message still says something', function() {
        return str_contains(Xml::summariseErrors(Xml::parse('<salesinvoice result="0"/>')), 'without saying why');
    });

    check('an external entity is not resolved', function() {
        // Twinfield responses arrive over the network from a third party; entity substitution is
        // the one libxml behaviour that turns that into file disclosure.
        $payload = '<?xml version="1.0"?><!DOCTYPE r [<!ENTITY xxe SYSTEM "file:///etc/passwd">]><r>&xxe;</r>';

        try {
            $doc = Xml::parse($payload);
        } catch (RuntimeException) {
            return true;
        }

        return !str_contains($doc->documentElement->textContent, 'root:') ?: 'the entity was resolved';
    });

    check('an empty value writes no element at all', function() {
        // Twinfield reads an empty element as "clear this field", which on an update wipes data a
        // bookkeeper set by hand.
        $doc = Xml::document('dimension');
        Xml::append($doc->documentElement, 'name', '');
        Xml::append($doc->documentElement, 'code', null);
        Xml::append($doc->documentElement, 'type', 'DEB');

        return Xml::toString($doc) === "<dimension>\n  <type>DEB</type>\n</dimension>" ?: Xml::toString($doc);
    });

    check('a literal ]]> is escaped so the document stays well-formed', function() {
        // DOM will not do this: it escapes `<` and `&` but leaves `>` alone, and a bare `]]>` is
        // illegal in XML content — so an invoice footer containing one would produce a document
        // Twinfield rejects as malformed.
        $doc = Xml::document('salesinvoice');
        Xml::append($doc->documentElement, 'footertext', 'a]]>b');
        $serialised = Xml::toString($doc);

        return (!str_contains($serialised, ']]>')
            && Xml::childText(Xml::parse($serialised)->documentElement, 'footertext') === 'a]]>b')
            ?: $serialised;
    });

    check('a value with XML metacharacters is escaped, not injected', function() {
        $doc = Xml::document('salesinvoice');
        Xml::append($doc->documentElement, 'headertext', 'Bolts & <nuts> "x"');
        $reparsed = Xml::parse(Xml::toString($doc));

        return Xml::childText($reparsed->documentElement, 'headertext') === 'Bolts & <nuts> "x"';
    });

    check('serialisation carries no XML declaration', function() {
        // A nested XML declaration inside a SOAP body makes .NET reject the whole envelope with
        // an unhelpful complaint about the request rather than the document. (And writing that
        // declaration out in a one-line comment would close PHP mode right here.)
        return !str_contains(Xml::toString(Xml::document('salesinvoice')), '<?xml');
    });

    // ---------------------------------------------------------------------
    section('Settings');

    check('the credit invoice type falls back to the sales invoice type', function() {
        $s = settings(['invoiceType' => 'FACTUUR', 'creditInvoiceType' => '']);

        return $s->getCreditInvoiceType() === 'FACTUUR' && $s->creditNeedsNegativeAmounts();
    });

    check('an explicit credit invoice type is used with positive amounts', function() {
        $s = settings(['creditInvoiceType' => 'CREDIT']);
        $result = $s->getCreditInvoiceType() === 'CREDIT' && !$s->creditNeedsNegativeAmounts();
        settings(['creditInvoiceType' => '']);

        return $result;
    });

    check('a concept invoice does not count as posted final', function() {
        return !settings(['mode' => Settings::MODE_SALES_INVOICE, 'invoiceStatus' => Settings::INVOICE_STATUS_CONCEPT])->postsFinal();
    });

    check('reconciliation is impossible while documents are provisional', function() {
        // A concept invoice is not a financial transaction, so there is nothing to read an open
        // value from — enabling reconciliation must not make it look like there is.
        $s = settings(['reconcileEnabled' => true, 'invoiceStatus' => Settings::INVOICE_STATUS_CONCEPT]);
        $result = !$s->canReconcile();
        settings(['reconcileEnabled' => false]);

        return $result;
    });

    check('the redirect URI is a control panel URL', function() {
        return str_contains(settings()->getRedirectUri(), 'twinsies/auth/callback');
    });

    check('credentials are read through the environment parser', function() {
        $s = settings(['clientId' => '$PRIMARY_SITE_URL']);
        $parsed = $s->getClientId();
        settings(['clientId' => 'fixture-client']);

        return $parsed !== '$PRIMARY_SITE_URL' ?: 'env vars are not being parsed';
    });

    // ---------------------------------------------------------------------
    section('The SOAP envelope');

    check('the envelope names the Twinfield header namespace', function() {
        global $plugin;
        $envelope = $plugin->getApi()->buildProcessEnvelope('<list><type>offices</type></list>');

        return str_contains($envelope, 'xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"')
            && str_contains($envelope, 'http://www.twinfield.com/');
    });

    check('the access token and company code are in the SOAP header', function() {
        global $plugin;
        $doc = Xml::parse($plugin->getApi()->buildProcessEnvelope('<list><type>offices</type></list>'));

        $token = $doc->getElementsByTagNameNS(Api::NS_TWINFIELD, 'AccessToken')->item(0);
        $company = $doc->getElementsByTagNameNS(Api::NS_TWINFIELD, 'CompanyCode')->item(0);

        return ($token?->textContent === 'fixture-access-token' && $company?->textContent === '001')
            ?: 'token=' . $token?->textContent . ' company=' . $company?->textContent;
    });

    check('an empty office omits CompanyCode entirely', function() {
        global $plugin;
        // Sending it empty gets "Company ontbreekt in request header" back; omitting it is what
        // makes the office listing work at all.
        $doc = Xml::parse($plugin->getApi()->buildProcessEnvelope('<list><type>offices</type></list>', ''));

        return $doc->getElementsByTagNameNS(Api::NS_TWINFIELD, 'CompanyCode')->length === 0;
    });

    check('the document is escaped into xmlRequest rather than wrapped in CDATA', function() {
        global $plugin;
        // A document containing "]]>" would be truncated inside a CDATA section. Escaping it
        // instead has to survive the round trip byte for byte, whatever the document contains.
        $inner = '<salesinvoice><footertext>a]]>b & <x></footertext></salesinvoice>';
        $envelope = $plugin->getApi()->buildProcessEnvelope($inner);

        if (str_contains($envelope, '<![CDATA[')) {
            return 'a CDATA section was used';
        }

        $carried = Xml::parse($envelope)
            ->getElementsByTagNameNS(Api::NS_TWINFIELD, 'xmlRequest')
            ->item(0)?->textContent;

        return $carried === $inner ?: "carried '{$carried}'";
    });

    check('the finder envelope carries its options as string pairs', function() {
        global $plugin;
        $doc = Xml::parse($plugin->getApi()->buildSearchEnvelope('DIM', '*', 0, 1, 100, ['dimtype' => 'PNL'], '001'));
        $strings = $doc->getElementsByTagNameNS(Api::NS_TWINFIELD, 'string');
        $values = [];

        foreach ($strings as $string) {
            $values[] = $string->textContent;
        }

        return ($values === ['dimtype', 'PNL']) ?: json_encode($values);
    });

    // ---------------------------------------------------------------------
    section('The transport');

    check('a successful call reaches the cluster URL with the right SOAPAction', function() {
        global $plugin;
        $history = scriptTwinfield([soapResponse('<offices><office name="Fixture BV">001</office></offices>')]);

        $plugin->getApi()->listing('offices', false);
        $request = $history[0]['request'];

        return ((string)$request->getUri() === 'https://api.accounting.twinfield.com/webservices/processxml.asmx'
            && $request->getHeaderLine('SOAPAction') === '"http://www.twinfield.com/ProcessXmlString"'
            && str_contains($request->getHeaderLine('Content-Type'), 'text/xml'))
            ?: (string)$request->getUri() . ' / ' . $request->getHeaderLine('SOAPAction');
    });

    check('a 401 refreshes the token and retries once', function() {
        global $plugin;

        // Script the identity server too, so the refresh is a real exchange.
        $authStack = HandlerStack::create(new MockHandler([
            new GuzzleResponse(200, [], json_encode([
                'access_token' => 'refreshed-token',
                'refresh_token' => 'fixture-refresh-token',
                'expires_in' => 3600,
            ])),
            new GuzzleResponse(200, [], json_encode(['twf.clusterUrl' => 'https://api.accounting.twinfield.com'])),
        ]));
        $plugin->getAuth()->setClient(new Client(['handler' => $authStack]));

        $history = scriptTwinfield([
            new GuzzleResponse(401, [], 'Unauthorized'),
            soapResponse('<offices><office name="Fixture BV">001</office></offices>'),
        ]);

        $plugin->getApi()->listing('offices', false);

        if (count($history) !== 2) {
            return 'sent ' . count($history) . ' requests';
        }

        // The retry must carry the *new* token, not the one that was just rejected.
        $retry = Xml::parse((string)$history[1]['request']->getBody());
        $token = $retry->getElementsByTagNameNS(Api::NS_TWINFIELD, 'AccessToken')->item(0)?->textContent;

        return $token === 'refreshed-token' ?: "retried with '{$token}'";
    });

    // Put the seeded grant back after that refresh rewrote it.
    seedConnection();

    check('a 401 is only retried once', function() {
        global $plugin;

        $authStack = HandlerStack::create(new MockHandler([
            new GuzzleResponse(200, [], json_encode(['access_token' => 'again', 'refresh_token' => 'r', 'expires_in' => 3600])),
            new GuzzleResponse(200, [], json_encode(['twf.clusterUrl' => 'https://api.accounting.twinfield.com'])),
        ]));
        $plugin->getAuth()->setClient(new Client(['handler' => $authStack]));

        $history = scriptTwinfield([
            new GuzzleResponse(401, [], 'Unauthorized'),
            new GuzzleResponse(401, [], 'Unauthorized'),
        ]);

        try {
            $plugin->getApi()->listing('offices', false);

            return 'no exception';
        } catch (TwinfieldException) {
            return count($history) === 2 ?: 'sent ' . count($history) . ' requests';
        }
    });

    seedConnection();

    check('a 503 is retried up to maxAttempts and then throws', function() {
        global $plugin;
        settings(['maxAttempts' => 2]);

        $history = scriptTwinfield([
            new GuzzleResponse(503, [], 'busy'),
            new GuzzleResponse(503, [], 'busy'),
        ]);

        try {
            $plugin->getApi()->listing('offices', false);

            return 'no exception';
        } catch (TwinfieldException $e) {
            return (count($history) === 2 && $e->retryable) ?: 'sent ' . count($history) . ', retryable=' . var_export($e->retryable, true);
        }
    });

    check('a 400 is not retried', function() {
        global $plugin;
        $history = scriptTwinfield([new GuzzleResponse(400, [], 'nope')]);

        try {
            $plugin->getApi()->listing('offices', false);

            return 'no exception';
        } catch (TwinfieldException $e) {
            return (count($history) === 1 && !$e->retryable) ?: 'sent ' . count($history);
        }
    });

    check('a write that timed out is not sent again', function() {
        global $plugin;
        // A read timeout arrives as a ConnectException, exactly like a refused connection — and
        // Twinfield may have posted the document before it timed out.
        settings(['maxAttempts' => 3]);
        $history = scriptTwinfield([
            new GuzzleHttp\Exception\ConnectException('cURL error 28: timed out', new GuzzleHttp\Psr7\Request('POST', 'https://api.accounting.twinfield.com'), null, ['errno' => 28]),
            soapResponse('<dimension result="1"/>'),
        ]);

        try {
            $plugin->getApi()->process('<dimension><office>001</office><type>DEB</type><code>9999</code></dimension>', 'test.write');

            return 'no exception';
        } catch (TwinfieldException $e) {
            settings(['maxAttempts' => 2]);

            return (count($history) === 1 && $e->unconfirmed && !$e->retryable) ?: 'sent ' . count($history) . ', unconfirmed=' . var_export($e->unconfirmed, true);
        }
    });

    check('a write that never connected is retried', function() {
        global $plugin;
        $history = scriptTwinfield([
            new GuzzleHttp\Exception\ConnectException('cURL error 7: refused', new GuzzleHttp\Psr7\Request('POST', 'https://api.accounting.twinfield.com'), null, ['errno' => 7]),
            soapResponse('<dimension result="1"/>'),
        ]);

        $plugin->getApi()->process('<dimension><office>001</office><type>DEB</type><code>9999</code></dimension>', 'test.write');

        return count($history) === 2 ?: 'sent ' . count($history);
    });

    check('a 500 on a write is not retried, but a 503 is', function() {
        global $plugin;
        $history = scriptTwinfield([new GuzzleResponse(500, [], 'oops')]);

        try {
            $plugin->getApi()->process('<dimension><office>001</office><type>DEB</type><code>9999</code></dimension>', 'test.write');

            return '500: no exception';
        } catch (TwinfieldException) {
            if (count($history) !== 1) {
                return '500: sent ' . count($history);
            }
        }

        $history = scriptTwinfield([new GuzzleResponse(503, [], 'busy'), soapResponse('<dimension result="1"/>')]);
        $plugin->getApi()->process('<dimension><office>001</office><type>DEB</type><code>9999</code></dimension>', 'test.write');

        return count($history) === 2 ?: '503: sent ' . count($history);
    });

    check('a SOAP fault is reported by its faultstring, not its status code', function() {
        global $plugin;
        scriptTwinfield([new GuzzleResponse(500, [], '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body><soap:Fault><faultstring>Toegang geweigerd</faultstring></soap:Fault></soap:Body></soap:Envelope>')]);
        settings(['maxAttempts' => 1]);

        try {
            $plugin->getApi()->listing('offices', false);

            return 'no exception';
        } catch (TwinfieldException $e) {
            settings(['maxAttempts' => 2]);

            return str_contains($e->getMessage(), 'Toegang geweigerd') ?: $e->getMessage();
        }
    });

    check('a read builds the request with type first', function() {
        global $plugin;
        // Twinfield reads <type> to decide how to interpret the rest; a read with it further down
        // returns an empty document rather than an error.
        $history = scriptTwinfield([soapResponse('<dimension><code>1000</code><name>Fixture</name></dimension>')]);

        $plugin->getApi()->read(['type' => 'dimensions', 'dimtype' => 'DEB', 'code' => '1000']);
        $sent = sentDocument($history);
        $first = null;

        foreach ($sent->documentElement->childNodes as $node) {
            if ($node instanceof DOMElement) {
                $first = $node->nodeName;
                break;
            }
        }

        return $first === 'type' ?: "first element was {$first}";
    });

    check('a finder response is parsed into rows', function() {
        global $plugin;

        $body = '<?xml version="1.0" encoding="utf-8"?>'
            . '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body>'
            . '<SearchResponse xmlns="http://www.twinfield.com/"><SearchResult/><data>'
            . '<TotalRows>2</TotalRows><Columns><string>Code</string><string>Name</string></Columns>'
            . '<Items><ArrayOfString><string>VH</string><string>BTW hoog 21%</string></ArrayOfString>'
            . '<ArrayOfString><string>VL</string><string>BTW laag 9%</string></ArrayOfString></Items>'
            . '</data></SearchResponse></soap:Body></soap:Envelope>';

        scriptTwinfield([new GuzzleResponse(200, ['Content-Type' => 'text/xml'], $body)]);
        $result = $plugin->getApi()->search('VAT', '*');

        return ($result['total'] === 2 && $result['rows'][1] === ['VL', 'BTW laag 9%'])
            ?: json_encode($result);
    });

    // ---------------------------------------------------------------------
    section('The grant');

    check('tokens are stored encrypted, not in the clear', function() {
        $row = (new craft\db\Query())->from([Table::AUTH])->one();

        return (!str_contains((string)$row['refreshToken'], 'fixture-refresh-token'))
            ?: 'the refresh token is readable in the database';
    });

    check('tokens decrypt back', function() {
        global $plugin;

        return $plugin->getAuth()->getConnection()?->refreshToken === 'fixture-refresh-token';
    });

    check('an undecryptable grant reports itself unreadable rather than fatalling', function() {
        global $plugin;

        Craft::$app->getDb()->createCommand()->update(Table::AUTH, ['refreshToken' => 'not-actually-encrypted'])->execute();
        resetService('auth', Auth::class);

        $connection = $plugin->getAuth()->getConnection();
        $result = ($connection !== null && !$connection->readable) ?: 'readable=' . var_export($connection?->readable, true);

        seedConnection();

        return $result;
    });

    check('a changed client ID invalidates the grant', function() {
        global $plugin;
        // Reusing a grant issued to a different OAuth app would be quietly wrong, not merely
        // broken.
        settings(['clientId' => 'a-different-client']);
        $result = !$plugin->getAuth()->isConnected();
        settings(['clientId' => 'fixture-client']);

        return $result;
    });

    check('the connection is otherwise reported as connected', function() {
        global $plugin;

        return $plugin->getAuth()->isConnected();
    });

    check('the cluster URL comes off the validated token', function() {
        global $plugin;

        return $plugin->getAuth()->getClusterUrl() === 'https://api.accounting.twinfield.com';
    });

    check('the authorisation URL asks for offline_access', function() {
        global $plugin;
        $url = $plugin->getAuth()->getAuthorizationUrl();

        return (str_contains($url, 'offline_access') && str_contains($url, 'response_type=code') && str_contains($url, 'state='))
            ?: $url;
    });

    check('a callback with the wrong state is refused', function() {
        global $plugin;

        try {
            $plugin->getAuth()->completeAuthorization('some-code', 'not-the-state-we-issued');

            return 'no exception';
        } catch (RuntimeException $e) {
            return str_contains($e->getMessage(), 'did not match this session') ?: $e->getMessage();
        }
    });

    check('an expired access token triggers a refresh before use', function() {
        global $plugin;
        seedConnection((new DateTime())->modify('-5 minutes'));

        $authStack = HandlerStack::create(new MockHandler([
            new GuzzleResponse(200, [], json_encode(['access_token' => 'fresh-token', 'refresh_token' => 'fixture-refresh-token', 'expires_in' => 3600])),
            new GuzzleResponse(200, [], json_encode(['twf.clusterUrl' => 'https://api.accounting.twinfield.com'])),
        ]));
        $plugin->getAuth()->setClient(new Client(['handler' => $authStack]));

        $token = $plugin->getAuth()->getAccessToken();
        seedConnection();

        return $token === 'fresh-token' ?: "got '{$token}'";
    });

    check('a refresh that returns no new refresh token keeps the old one', function() {
        global $plugin;
        seedConnection((new DateTime())->modify('-5 minutes'));

        $authStack = HandlerStack::create(new MockHandler([
            new GuzzleResponse(200, [], json_encode(['access_token' => 'fresh-token', 'expires_in' => 3600])),
            new GuzzleResponse(200, [], json_encode(['twf.clusterUrl' => 'https://api.accounting.twinfield.com'])),
        ]));
        $plugin->getAuth()->setClient(new Client(['handler' => $authStack]));

        $plugin->getAuth()->refresh();
        resetService('auth', Auth::class);
        $kept = $plugin->getAuth()->getConnection()?->refreshToken;
        seedConnection();

        return $kept === 'fixture-refresh-token' ?: "kept '{$kept}'";
    });

    // ---------------------------------------------------------------------
    section('Debtor codes');

    $debtorOrder = makeOrder([['variant' => makeProduct('TW-DEB-' . StringHelper::randomString(4), 10.00)->getVariants()[0], 'qty' => 1]]);

    check('an order is keyed on its Craft user when it has one', function() {
        global $plugin;
        // Commerce 5 associates a customer User with any order that carries an email, so the
        // user is the stabler identity — an address can change, the account does not.
        $variant = makeProduct('TW-CODE-' . StringHelper::randomString(4), 10.00)->getVariants()[0];
        $order = makeOrder([['variant' => $variant, 'qty' => 1]]);

        [$type, $key] = $plugin->getCustomers()->identify($order);

        return ($type === 'user' && $key === (string)$order->getCustomer()->id) ?: "$type / $key";
    });

    check('an order with neither user nor email keys on itself', function() {
        global $plugin, $createdOrders, $storeId;
        // The last resort. A debtor still has to be identified somehow, or two unrelated guest
        // orders would share one. Built without an email, because Commerce attaches a customer
        // User to any order that has one.
        $order = new Order();
        $order->storeId = $storeId;
        $order->orderSiteId = Craft::$app->getSites()->getPrimarySite()->id;
        $order->number = Commerce::getInstance()->getCarts()->generateCartNumber();

        if (!Craft::$app->getElements()->saveElement($order, false, true, false)) {
            return 'could not save the anonymous cart';
        }

        $createdOrders[] = $order;

        [$type, $key] = $plugin->getCustomers()->identify($order);

        return ($type === 'guest' && $key === (string)$order->id) ?: "$type / $key";
    });

    check('an email becomes a hashed code rather than a truncated one', function() {
        global $plugin;
        // Truncating would collide between alexander@ and alexandra@ on the same day the shop got
        // both.
        $a = $plugin->getCustomers()->normaliseCode('alexander@averylongdomainname.example');
        $b = $plugin->getCustomers()->normaliseCode('alexandra@averylongdomainname.example');

        return ($a !== $b && strlen($a) <= 16) ?: "$a / $b";
    });

    check('a code is upper case and alphanumeric', function() {
        global $plugin;

        return $plugin->getCustomers()->normaliseCode('web-1234') === 'WEB1234';
    });

    check('an unusable code still produces something', function() {
        global $plugin;
        $code = $plugin->getCustomers()->normaliseCode('!!!');

        return (strlen($code) > 0 && strlen($code) <= 16) ?: $code;
    });

    check('the auto strategy leaves numbering to Twinfield', function() use ($debtorOrder) {
        global $plugin;
        // A code derived from Craft collides the moment a bookkeeper also enters customers by
        // hand; Twinfield's own numbering never does.
        settings(['customerCodeStrategy' => Settings::CODE_AUTO]);

        return $plugin->getCustomers()->deriveCode($debtorOrder, 'email', 'a@b.test') === null;
    });

    check('the prefix is applied to a derived code', function() use ($debtorOrder) {
        global $plugin;
        settings(['customerCodeStrategy' => Settings::CODE_EMAIL, 'customerCodePrefix' => 'WEB']);
        $code = $plugin->getCustomers()->deriveCode($debtorOrder, 'email', 'dana@example.com');
        settings(['customerCodeStrategy' => Settings::CODE_AUTO, 'customerCodePrefix' => '']);

        return str_starts_with((string)$code, 'WEB') ?: (string)$code;
    });

    check('a mapped debtor is remembered and read back', function() {
        global $plugin;
        $plugin->getCustomers()->rememberCode('001', 'email', 'remembered@example.com', '2001', 'Remembered BV');
        $found = $plugin->getCustomers()->getStoredCode('001', 'email', 'remembered@example.com');
        $plugin->getCustomers()->forget('001', 'email', 'remembered@example.com');

        return $found === '2001' ?: var_export($found, true);
    });

    check('the debtor document is a DEB dimension with an invoice address', function() use ($debtorOrder) {
        global $plugin;
        settings(['syncCustomers' => true, 'phoneFieldHandle' => '', 'vatNumberFieldHandle' => '']);

        $history = scriptTwinfield([soapResponse('<dimension result="1"><code>1042</code><name>Fixture BV</name></dimension>')]);

        $code = $plugin->getCustomers()->writeDimension($debtorOrder, null);
        $sent = sentDocument($history);
        $root = $sent->documentElement;
        $address = $sent->getElementsByTagName('address')->item(0);

        settings(['syncCustomers' => false]);

        if ($root->nodeName !== 'dimension' || Xml::childText($root, 'type') !== 'DEB') {
            return 'root was ' . $root->nodeName . ' type ' . Xml::childText($root, 'type');
        }

        if ($address === null || $address->getAttribute('type') !== 'invoice') {
            return 'no invoice address';
        }

        if (Xml::childText($address, 'country') !== 'NL' || Xml::childText($address, 'field1') !== 'Keizersgracht 1') {
            return 'address fields wrong';
        }

        // The auto-numbered code has to be read out of the response, not assumed.
        return $code === '1042' ?: "code came back as '{$code}'";
    });

    check('an auto-numbered debtor omits <code> rather than sending it empty', function() use ($debtorOrder) {
        global $plugin;
        $history = scriptTwinfield([soapResponse('<dimension result="1"><code>1043</code></dimension>')]);

        $plugin->getCustomers()->writeDimension($debtorOrder, null);
        $sent = sentDocument($history);

        return Xml::childText($sent->documentElement, 'code') === null ?: 'a code element was sent';
    });

    check('an unknown phone field handle is ignored rather than fatal', function() use ($debtorOrder) {
        global $plugin;
        // getFieldValue() throws on a handle the layout does not have, so a settings typo would
        // otherwise take down every push.
        settings(['syncCustomers' => true, 'phoneFieldHandle' => 'noSuchFieldHandle']);
        scriptTwinfield([soapResponse('<dimension result="1"><code>1044</code></dimension>')]);

        $code = $plugin->getCustomers()->writeDimension($debtorOrder, null);
        settings(['syncCustomers' => false, 'phoneFieldHandle' => '']);

        return $code === '1044' ?: 'writing the dimension failed';
    });

    // ---------------------------------------------------------------------
    section('Mapping');

    check('a rate maps to its VAT code', function() {
        global $plugin;
        settings(['vatSource' => Settings::VAT_BY_RATE]);

        return $plugin->getMapping()->vatCodeFor(100.0, 21.0) === 'VH';
    });

    check('a different rate maps to a different code', function() {
        global $plugin;

        return $plugin->getMapping()->vatCodeFor(100.0, 9.0) === 'VL';
    });

    check('a rate written with a percent sign still matches', function() {
        global $plugin;
        settings(['vatRateMap' => [['rate' => '21%', 'vatCode' => 'VH']]]);
        $result = $plugin->getMapping()->vatCodeFor(100.0, 21.0) === 'VH';
        settings(['vatRateMap' => [['rate' => '21', 'vatCode' => 'VH'], ['rate' => '9', 'vatCode' => 'VL']]]);

        return $result;
    });

    check('no tax at all uses the zero-rate code, not the fallback', function() {
        global $plugin;
        // Zero-rated exports and reverse-charged intra-EU sales both look like zero here, and
        // both need a real code rather than the standard-rate fallback.
        return $plugin->getMapping()->vatCodeFor(100.0, 0.0) === 'VN';
    });

    check('an unmatched rate falls back', function() {
        global $plugin;

        return $plugin->getMapping()->vatCodeFor(100.0, 13.0) === 'VH';
    });

    check('matching by tax category uses the category map', function() {
        global $plugin;
        settings(['vatSource' => Settings::VAT_BY_CATEGORY, 'vatCategoryMap' => [['category' => 'reduced', 'vatCode' => 'VL']]]);
        $result = $plugin->getMapping()->vatCodeFor(100.0, 21.0, 'reduced') === 'VL';
        settings(['vatSource' => Settings::VAT_BY_RATE]);

        return $result;
    });

    check('a saved mapping row is read back', function() {
        global $plugin;
        $plugin->getMapping()->saveMap(new ArticleMap([
            'office' => '001',
            'mapKey' => ArticleMap::KEY_SHIPPING,
            'article' => 'VERZEND',
            'revenueGl' => '8100',
        ]));
        resetService('mapping', Mapping::class);

        $map = $plugin->getMapping()->getMap(ArticleMap::KEY_SHIPPING);

        return ($map?->article === 'VERZEND' && $map->revenueGl === '8100') ?: 'not saved';
    });

    check('an emptied mapping row is deleted rather than stored blank', function() {
        global $plugin;
        $plugin->getMapping()->saveMap(new ArticleMap(['office' => '001', 'mapKey' => ArticleMap::KEY_SHIPPING]));
        resetService('mapping', Mapping::class);

        return $plugin->getMapping()->getMap(ArticleMap::KEY_SHIPPING) === null;
    });

    check('a product type mapping beats the catch-all', function() {
        global $plugin;
        $productType = Commerce::getInstance()->getProductTypes()->getAllProductTypes()[0];

        $plugin->getMapping()->saveMap(new ArticleMap([
            'office' => '001',
            'mapKey' => ArticleMap::KEY_DEFAULT,
            'article' => 'GENERAL',
            'revenueGl' => '8000',
        ]));
        $plugin->getMapping()->saveMap(new ArticleMap([
            'office' => '001',
            'mapKey' => ArticleMap::productTypeKey($productType->handle),
            'article' => 'BYTYPE',
            'revenueGl' => '8050',
        ]));
        resetService('mapping', Mapping::class);

        $variant = makeProduct('TW-MAP-' . StringHelper::randomString(4), 20.00)->getVariants()[0];
        $order = makeOrder([['variant' => $variant, 'qty' => 1]]);
        $mapped = $plugin->getMapping()->forLineItem($order->getLineItems()[0]);

        return ($mapped->article === 'BYTYPE' && $mapped->revenueGl === '8050') ?: "{$mapped->article} / {$mapped->revenueGl}";
    });

    check('a purchasable mapping beats its product type', function() {
        global $plugin;
        $variant = makeProduct('TW-MAP2-' . StringHelper::randomString(4), 20.00)->getVariants()[0];

        $plugin->getMapping()->saveMap(new ArticleMap([
            'office' => '001',
            'mapKey' => ArticleMap::purchasableKey($variant->id),
            'article' => 'EXACT',
            'revenueGl' => '8060',
        ]));
        resetService('mapping', Mapping::class);

        $order = makeOrder([['variant' => $variant, 'qty' => 1]]);
        $mapped = $plugin->getMapping()->forLineItem($order->getLineItems()[0]);

        return $mapped->article === 'EXACT' ?: $mapped->article;
    });

    // Clear the mapping rows so the document checks run on the settings defaults.
    foreach ($plugin->getMapping()->getMaps() as $map) {
        $plugin->getMapping()->deleteMap($map->mapKey);
    }
    resetService('mapping', Mapping::class);

    // ---------------------------------------------------------------------
    section('Sales invoice documents');

    $invoiceVariant = makeProduct('TW-INV-' . StringHelper::randomString(4), 49.50)->getVariants()[0];
    $invoiceOrder = makeOrder([['variant' => $invoiceVariant, 'qty' => 2]]);

    check('a preview writes nothing to Twinfield, and says what a push would create', function() {
        global $plugin;
        // Preview once created the debtor and any articles, and so did a build that then refused
        // to reconcile.
        settings(['mode' => Settings::MODE_SALES_INVOICE, 'guestCustomerCode' => '', 'syncCustomers' => true,
            'autoCreateArticles' => true, 'defaultArticle' => 'TWDRY']);

        $variant = makeProduct('TW-DRY-' . StringHelper::randomString(4), 20.00)->getVariants()[0];
        $order = makeOrder([['variant' => $variant, 'qty' => 1]]);

        $history = scriptTwinfield([]);
        try {
            $built = $plugin->getDocuments()->build($order, dryRun: true);
        } finally {
            settings(['syncCustomers' => false, 'autoCreateArticles' => false, 'defaultArticle' => '']);
        }

        if (count($history) !== 0) {
            return 'sent ' . count($history) . ' requests';
        }

        $said = implode(' | ', $built->warnings);

        return (str_contains($said, 'debtor') && str_contains($said, 'article')) ?: $said;
    });

    check('the header carries the office, invoice type, dates and status', function() use ($invoiceOrder) {
        global $plugin;
        settings(['mode' => Settings::MODE_SALES_INVOICE, 'guestCustomerCode' => '1000', 'syncCustomers' => false]);

        $built = $plugin->getDocuments()->build($invoiceOrder);
        $doc = Xml::parse($built->xml);
        $header = Xml::first($doc, 'header');

        $expected = [
            'office' => '001',
            'invoicetype' => 'FACTUUR',
            'customer' => '1000',
            'status' => 'concept',
            'paymentmethod' => 'bank',
            'currency' => strtoupper($invoiceOrder->currency),
        ];

        foreach ($expected as $tag => $value) {
            if (Xml::childText($header, $tag) !== $value) {
                return "{$tag} was '" . Xml::childText($header, $tag) . "', expected '{$value}'";
            }
        }

        return true;
    });

    check('the due date is the order date plus the payment terms', function() use ($invoiceOrder) {
        global $plugin;
        settings(['dueDays' => 30]);
        $header = Xml::first(Xml::parse($plugin->getDocuments()->build($invoiceOrder)->xml), 'header');
        settings(['dueDays' => 14]);

        $ordered = Dates::parse(Xml::childText($header, 'invoicedate'));
        $due = Dates::parse(Xml::childText($header, 'duedate'));

        return ($ordered && $due && $ordered->diff($due)->days === 30) ?: 'diff was ' . $ordered?->diff($due)->days;
    });

    check('the period is sent as yyyy/PP', function() use ($invoiceOrder) {
        global $plugin;
        $header = Xml::first(Xml::parse($plugin->getDocuments()->build($invoiceOrder)->xml), 'header');

        return (bool)preg_match('#^\d{4}/\d{2}$#', (string)Xml::childText($header, 'period'));
    });

    check('the period can be left off for a shifted book year', function() use ($invoiceOrder) {
        global $plugin;
        settings(['sendPeriod' => false]);
        $header = Xml::first(Xml::parse($plugin->getDocuments()->build($invoiceOrder)->xml), 'header');
        settings(['sendPeriod' => true]);

        return Xml::childText($header, 'period') === null;
    });

    check('each order line becomes one invoice line with a sequential id', function() use ($invoiceOrder) {
        global $plugin;
        $doc = Xml::parse($plugin->getDocuments()->build($invoiceOrder)->xml);
        $lines = Xml::children(Xml::first($doc, 'lines'), 'line');

        return (count($lines) === 1 && $lines[0]->getAttribute('id') === '1') ?: count($lines) . ' lines';
    });

    check('the line carries the quantity and an ex-VAT unit price', function() use ($invoiceOrder) {
        global $plugin;
        $line = Xml::children(Xml::first(Xml::parse($plugin->getDocuments()->build($invoiceOrder)->xml), 'lines'), 'line')[0];

        return (Xml::childText($line, 'quantity') === '2' && Xml::childText($line, 'unitspriceexcl') === '49.50')
            ?: Xml::childText($line, 'quantity') . ' @ ' . Xml::childText($line, 'unitspriceexcl');
    });

    check('no tax line is ever emitted', function() use ($invoiceOrder) {
        global $plugin;
        // Twinfield derives VAT from the line's vatcode and books it itself; a tax line as well
        // charges the VAT twice, and Twinfield accepts it because it is arithmetically consistent.
        $xml = $plugin->getDocuments()->build($invoiceOrder)->xml;

        return !str_contains(strtolower($xml), '<vatvalue')
            && !preg_match('/<description>[^<]*(tax|btw)[^<]*<\/description>/i', $xml);
    });

    check('the line books to the configured revenue account', function() use ($invoiceOrder) {
        global $plugin;
        $line = Xml::children(Xml::first(Xml::parse($plugin->getDocuments()->build($invoiceOrder)->xml), 'lines'), 'line')[0];

        return Xml::childText($line, 'dim1') === '8000' ?: Xml::childText($line, 'dim1');
    });

    check('discounts on the article are switched off', function() use ($invoiceOrder) {
        global $plugin;
        // Order-level discounts are already in the line amounts; letting Twinfield apply the
        // article's own rule on top would deduct them twice.
        $line = Xml::children(Xml::first(Xml::parse($plugin->getDocuments()->build($invoiceOrder)->xml), 'lines'), 'line')[0];

        return Xml::childText($line, 'allowdiscountorpremium') === 'false';
    });

    check('the description is truncated to what Twinfield accepts', function() {
        global $plugin;
        settings(['lineDescriptionTemplate' => str_repeat('x', 300)]);
        $variant = makeProduct('TW-DESC-' . StringHelper::randomString(4), 5.00)->getVariants()[0];
        $order = makeOrder([['variant' => $variant, 'qty' => 1]]);

        $line = Xml::children(Xml::first(Xml::parse($plugin->getDocuments()->build($order)->xml), 'lines'), 'line')[0];
        settings(['lineDescriptionTemplate' => '{{ object.description }}']);

        $description = (string)Xml::childText($line, 'description');

        return mb_strlen($description) === 110 ?: 'length was ' . mb_strlen($description);
    });

    check('the SKU rides along in freetext1', function() use ($invoiceOrder, $invoiceVariant) {
        global $plugin;
        $line = Xml::children(Xml::first(Xml::parse($plugin->getDocuments()->build($invoiceOrder)->xml), 'lines'), 'line')[0];

        return Xml::childText($line, 'freetext1') === $invoiceVariant->sku;
    });

    check('the footer text is rendered as an object template', function() use ($invoiceOrder) {
        global $plugin;
        $header = Xml::first(Xml::parse($plugin->getDocuments()->build($invoiceOrder)->xml), 'header');

        return Xml::childText($header, 'footertext') === 'Order ' . $invoiceOrder->reference
            ?: Xml::childText($header, 'footertext');
    });

    check('a broken object template does not take the document down with it', function() use ($invoiceOrder) {
        global $plugin;
        settings(['footerText' => '{{ object.thisDoesNot.exist() }}']);
        $built = $plugin->getDocuments()->build($invoiceOrder);
        settings(['footerText' => 'Order {{ object.reference }}']);

        return Xml::childText(Xml::first(Xml::parse($built->xml), 'header'), 'footertext') === null;
    });

    check('an order reference with letters is not sent as an invoice number', function() use ($invoiceOrder) {
        global $plugin;
        // Twinfield's invoice number is an integer, so a reference like "2026-0042" would post as
        // a rejected document rather than a numbered one.
        settings(['sendOwnInvoiceNumber' => true]);
        $built = $plugin->getDocuments()->build($invoiceOrder);
        $number = Xml::childText(Xml::first(Xml::parse($built->xml), 'header'), 'invoicenumber');
        settings(['sendOwnInvoiceNumber' => false]);

        return $number === null || ctype_digit($number) ?: "sent '{$number}'";
    });

    check('the built total matches the order total', function() use ($invoiceOrder) {
        global $plugin;
        $built = $plugin->getDocuments()->build($invoiceOrder);

        return Amounts::equal($built->total, $invoiceOrder->getTotalPrice())
            ?: $built->total . ' vs ' . $invoiceOrder->getTotalPrice();
    });

    check('a document with no debtor at all refuses to build', function() {
        global $plugin;
        settings(['guestCustomerCode' => '', 'syncCustomers' => false]);
        $variant = makeProduct('TW-NODEB-' . StringHelper::randomString(4), 10.00)->getVariants()[0];
        $order = makeOrder([['variant' => $variant, 'qty' => 1]]);

        try {
            $plugin->getDocuments()->build($order);
            settings(['guestCustomerCode' => '1000']);

            return 'no exception';
        } catch (RuntimeException $e) {
            settings(['guestCustomerCode' => '1000']);

            return str_contains($e->getMessage(), 'debtor') ?: $e->getMessage();
        }
    });

    // ---------------------------------------------------------------------
    section('Included tax and adjustments');

    check('included tax is taken out of the unit price', function() {
        global $plugin;
        // When a store prices inclusive of VAT, Commerce's line subtotal is the gross figure.
        // Sending it as unitspriceexcl overstates every invoice by the VAT rate.
        $variant = makeProduct('TW-INC-' . StringHelper::randomString(4), 121.00)->getVariants()[0];
        $order = makeOrder([['variant' => $variant, 'qty' => 1]], true, [
            ['type' => 'tax', 'amount' => 21.00, 'included' => true, 'lineItemIndex' => 1],
        ]);

        $line = Xml::children(Xml::first(Xml::parse($plugin->getDocuments()->build($order)->xml), 'lines'), 'line')[0];

        return Xml::childText($line, 'unitspriceexcl') === '100.00' ?: Xml::childText($line, 'unitspriceexcl');
    });

    check('an included-tax order still totals what the customer paid', function() {
        global $plugin;
        $variant = makeProduct('TW-INC2-' . StringHelper::randomString(4), 121.00)->getVariants()[0];
        $order = makeOrder([['variant' => $variant, 'qty' => 1]], true, [
            ['type' => 'tax', 'amount' => 21.00, 'included' => true, 'lineItemIndex' => 1],
        ]);

        $built = $plugin->getDocuments()->build($order);

        return Amounts::equal($built->total, 121.00) ?: (string)$built->total;
    });

    check('shipping becomes its own line on its own account', function() {
        global $plugin;
        $variant = makeProduct('TW-SHIP-' . StringHelper::randomString(4), 40.00)->getVariants()[0];
        $order = makeOrder([['variant' => $variant, 'qty' => 1]], true, [
            ['type' => 'shipping', 'amount' => 6.95, 'name' => 'PostNL'],
        ]);

        $lines = Xml::children(Xml::first(Xml::parse($plugin->getDocuments()->build($order)->xml), 'lines'), 'line');
        $shipping = null;

        foreach ($lines as $line) {
            if (Xml::childText($line, 'dim1') === '8100') {
                $shipping = $line;
            }
        }

        return ($shipping !== null && Xml::childText($shipping, 'article') === 'VERZEND'
            && Xml::childText($shipping, 'unitspriceexcl') === '6.95')
            ?: count($lines) . ' lines, no shipping line on 8100';
    });

    check('an order-level discount becomes a negative line', function() {
        global $plugin;
        $variant = makeProduct('TW-DISC-' . StringHelper::randomString(4), 40.00)->getVariants()[0];
        $order = makeOrder([['variant' => $variant, 'qty' => 1]], true, [
            ['type' => 'discount', 'amount' => -5.00, 'name' => 'Coupon'],
        ]);

        $lines = Xml::children(Xml::first(Xml::parse($plugin->getDocuments()->build($order)->xml), 'lines'), 'line');

        foreach ($lines as $line) {
            if (Xml::childText($line, 'dim1') === '8200') {
                return Xml::childText($line, 'unitspriceexcl') === '-5.00' ?: Xml::childText($line, 'unitspriceexcl');
            }
        }

        return 'no discount line on 8200';
    });

    check('an adjustment type Twinsies cannot express fails the build, naming it', function() {
        global $plugin;
        // Booking an unexplained difference to a revenue account produces books that reconcile
        // and are wrong. Failing costs a support ticket; the alternative costs an audit.
        $variant = makeProduct('TW-ODD-' . StringHelper::randomString(4), 40.00)->getVariants()[0];
        $order = makeOrder([['variant' => $variant, 'qty' => 1]], true, [
            ['type' => 'gratuity', 'amount' => 7.50, 'name' => 'Tip'],
        ]);

        try {
            $plugin->getDocuments()->build($order);

            return 'no exception';
        } catch (RuntimeException $e) {
            return str_contains($e->getMessage(), 'gratuity') ?: $e->getMessage();
        }
    });

    check('a rounding cent is booked to a visible line, not swallowed', function() {
        global $plugin;
        $variant = makeProduct('TW-ROUND-' . StringHelper::randomString(4), 40.00)->getVariants()[0];
        $order = makeOrder([['variant' => $variant, 'qty' => 1]], true, [
            ['type' => 'gratuity', 'amount' => 0.02, 'name' => 'Rounding'],
        ]);

        $built = $plugin->getDocuments()->build($order);

        return (str_contains($built->xml, 'Rounding') && $built->warnings !== [])
            ?: 'warnings: ' . json_encode($built->warnings);
    });

    // ---------------------------------------------------------------------
    section('Journal transaction documents');

    check('a transaction has a total line against the debtor account', function() use ($invoiceOrder) {
        global $plugin;
        settings(['mode' => Settings::MODE_TRANSACTION]);

        $doc = Xml::parse($plugin->getDocuments()->build($invoiceOrder)->xml);
        $total = null;

        foreach (Xml::children(Xml::first($doc, 'lines'), 'line') as $line) {
            if ($line->getAttribute('type') === 'total') {
                $total = $line;
            }
        }

        return ($total !== null
            && Xml::childText($total, 'dim1') === '1300'
            && Xml::childText($total, 'dim2') === '1000'
            && Xml::childText($total, 'debitcredit') === 'debit')
            ?: 'total line wrong or missing';
    });

    check('the total line equals the order total', function() use ($invoiceOrder) {
        global $plugin;
        $doc = Xml::parse($plugin->getDocuments()->build($invoiceOrder)->xml);

        foreach (Xml::children(Xml::first($doc, 'lines'), 'line') as $line) {
            if ($line->getAttribute('type') === 'total') {
                return Amounts::equal((float)Xml::childText($line, 'value'), $invoiceOrder->getTotalPrice())
                    ?: Xml::childText($line, 'value') . ' vs ' . $invoiceOrder->getTotalPrice();
            }
        }

        return 'no total line';
    });

    check('revenue is credited while the debtor is debited', function() use ($invoiceOrder) {
        global $plugin;
        $doc = Xml::parse($plugin->getDocuments()->build($invoiceOrder)->xml);

        foreach (Xml::children(Xml::first($doc, 'lines'), 'line') as $line) {
            if ($line->getAttribute('type') === 'detail') {
                return Xml::childText($line, 'debitcredit') === 'credit' ?: Xml::childText($line, 'debitcredit');
            }
        }

        return 'no detail line';
    });

    check('the transaction carries the daybook and destiny', function() use ($invoiceOrder) {
        global $plugin;
        $doc = Xml::parse($plugin->getDocuments()->build($invoiceOrder)->xml);

        return ($doc->documentElement->getAttribute('destiny') === 'temporary'
            && Xml::childText(Xml::first($doc, 'header'), 'code') === 'VRK')
            ?: 'destiny=' . $doc->documentElement->getAttribute('destiny');
    });

    check('autobalancevat is declared so a rounding cent does not reject the posting', function() use ($invoiceOrder) {
        global $plugin;
        $doc = Xml::parse($plugin->getDocuments()->build($invoiceOrder)->xml);

        return $doc->documentElement->getAttribute('autobalancevat') === 'true';
    });

    check('the order reference is the invoice number on the journal', function() use ($invoiceOrder) {
        global $plugin;
        $header = Xml::first(Xml::parse($plugin->getDocuments()->build($invoiceOrder)->xml), 'header');

        return Xml::childText($header, 'invoicenumber') === $invoiceOrder->reference;
    });

    check('lines are grouped onto one journal line per account and VAT code', function() {
        global $plugin;
        // Twinfield's fair use guidance is 25 children to a parent; a hundred-line order does not
        // need a hundred journal lines.
        $a = makeProduct('TW-GRPA-' . StringHelper::randomString(4), 10.00)->getVariants()[0];
        $b = makeProduct('TW-GRPB-' . StringHelper::randomString(4), 15.00)->getVariants()[0];
        $order = makeOrder([['variant' => $a, 'qty' => 1], ['variant' => $b, 'qty' => 1]]);

        $doc = Xml::parse($plugin->getDocuments()->build($order)->xml);
        $details = 0;

        foreach (Xml::children(Xml::first($doc, 'lines'), 'line') as $line) {
            if ($line->getAttribute('type') === 'detail') {
                $details++;
            }
        }

        return $details === 1 ?: "{$details} detail lines for two same-account items";
    });

    check('explicit VAT lines reference the detail line they belong to', function() {
        global $plugin;
        settings(['emitVatLines' => true, 'vatGl' => '1500']);

        $variant = makeProduct('TW-VAT-' . StringHelper::randomString(4), 100.00)->getVariants()[0];
        $order = makeOrder([['variant' => $variant, 'qty' => 1]], true, [
            ['type' => 'tax', 'amount' => 21.00, 'included' => false, 'lineItemIndex' => 1],
        ]);

        $doc = Xml::parse($plugin->getDocuments()->build($order)->xml);
        settings(['emitVatLines' => false, 'vatGl' => '']);

        foreach (Xml::children(Xml::first($doc, 'lines'), 'line') as $line) {
            if ($line->getAttribute('type') === 'vat') {
                // Without <baseline> Twinfield cannot tie the VAT to a turnover figure and the
                // VAT return comes out short.
                return (Xml::childText($line, 'baseline') === '1' && Xml::childText($line, 'dim1') === '1500')
                    ?: 'baseline=' . Xml::childText($line, 'baseline');
            }
        }

        return 'no vat line was emitted';
    });

    check('a journal line never carries a negative value', function() {
        global $plugin;
        // Twinfield has no negative amounts on a transaction line: a credit is the same amount on
        // the other side, and a negative value posts a transaction that will not balance.
        $variant = makeProduct('TW-NEG-' . StringHelper::randomString(4), 40.00)->getVariants()[0];
        $order = makeOrder([['variant' => $variant, 'qty' => 1]], true, [
            ['type' => 'discount', 'amount' => -5.00, 'name' => 'Coupon'],
        ]);

        $doc = Xml::parse($plugin->getDocuments()->build($order)->xml);

        foreach (Xml::children(Xml::first($doc, 'lines'), 'line') as $line) {
            if (str_starts_with((string)Xml::childText($line, 'value'), '-')) {
                return 'a line carried ' . Xml::childText($line, 'value');
            }
        }

        return true;
    });

    check('the discount line flips to debit rather than going negative', function() {
        global $plugin;
        $variant = makeProduct('TW-NEG2-' . StringHelper::randomString(4), 40.00)->getVariants()[0];
        $order = makeOrder([['variant' => $variant, 'qty' => 1]], true, [
            ['type' => 'discount', 'amount' => -5.00, 'name' => 'Coupon'],
        ]);

        $doc = Xml::parse($plugin->getDocuments()->build($order)->xml);

        foreach (Xml::children(Xml::first($doc, 'lines'), 'line') as $line) {
            if (Xml::childText($line, 'dim1') === '8200') {
                return Xml::childText($line, 'debitcredit') === 'debit' ?: Xml::childText($line, 'debitcredit');
            }
        }

        return 'no discount line';
    });

    settings(['mode' => Settings::MODE_SALES_INVOICE]);

    // ---------------------------------------------------------------------
    section('Credit notes');

    check('a full credit mirrors the invoice with negative amounts', function() use ($invoiceOrder) {
        global $plugin;
        settings(['creditInvoiceType' => '']);

        $built = $plugin->getDocuments()->build($invoiceOrder, Document::KIND_CREDIT_NOTE);
        $line = Xml::children(Xml::first(Xml::parse($built->xml), 'lines'), 'line')[0];

        return str_starts_with((string)Xml::childText($line, 'unitspriceexcl'), '-')
            ?: Xml::childText($line, 'unitspriceexcl');
    });

    check('a dedicated credit invoice type gets positive amounts instead', function() use ($invoiceOrder) {
        global $plugin;
        settings(['creditInvoiceType' => 'CREDIT']);

        $built = $plugin->getDocuments()->build($invoiceOrder, Document::KIND_CREDIT_NOTE);
        $doc = Xml::parse($built->xml);
        $line = Xml::children(Xml::first($doc, 'lines'), 'line')[0];
        settings(['creditInvoiceType' => '']);

        return (Xml::childText(Xml::first($doc, 'header'), 'invoicetype') === 'CREDIT'
            && !str_starts_with((string)Xml::childText($line, 'unitspriceexcl'), '-'))
            ?: 'type=' . Xml::childText(Xml::first($doc, 'header'), 'invoicetype');
    });

    check('a partial credit is apportioned and warns that it was', function() use ($invoiceOrder) {
        global $plugin;
        $half = $invoiceOrder->getTotalPrice() / 2;
        $built = $plugin->getDocuments()->build($invoiceOrder, Document::KIND_CREDIT_NOTE, $half);

        return (Amounts::equal(abs($built->total), $half) && $built->warnings !== [])
            ?: 'total=' . $built->total . ' warnings=' . json_encode($built->warnings);
    });

    check('a credit note in journal mode reverses the sides', function() use ($invoiceOrder) {
        global $plugin;
        settings(['mode' => Settings::MODE_TRANSACTION]);

        $doc = Xml::parse($plugin->getDocuments()->build($invoiceOrder, Document::KIND_CREDIT_NOTE)->xml);
        settings(['mode' => Settings::MODE_SALES_INVOICE]);

        foreach (Xml::children(Xml::first($doc, 'lines'), 'line') as $line) {
            if ($line->getAttribute('type') === 'total') {
                return Xml::childText($line, 'debitcredit') === 'credit' ?: Xml::childText($line, 'debitcredit');
            }
        }

        return 'no total line';
    });

    // ---------------------------------------------------------------------
    section('Posting');

    check('a successful post records the invoice number Twinfield assigned', function() use ($invoiceOrder) {
        global $plugin;
        $history = scriptTwinfield([soapResponse(
            '<salesinvoice result="1"><header><invoicenumber>2026001</invoicenumber></header></salesinvoice>'
        )]);

        $document = $plugin->getSync()->pushOrder($invoiceOrder);

        return ($document->isSent() && $document->invoiceNumber === '2026001' && count($history) === 1)
            ?: $document->status . ' / ' . $document->invoiceNumber . ' / ' . $document->lastError;
    });

    check('a second push of the same order does not create a second row', function() use ($invoiceOrder) {
        global $plugin;
        // The unique index is the guarantee; the mutex is what stops two workers reaching the POST
        // at the same moment and creating two invoices no index could undo.
        scriptTwinfield([soapResponse('<salesinvoice result="1"><header><invoicenumber>2026002</invoicenumber></header></salesinvoice>')]);
        $plugin->getSync()->pushOrder($invoiceOrder);

        return count($plugin->getSync()->getDocumentsForOrder($invoiceOrder->id)) === 1
            ?: count($plugin->getSync()->getDocumentsForOrder($invoiceOrder->id)) . ' rows';
    });

    check('an already-posted document is not posted again without force', function() use ($invoiceOrder) {
        global $plugin;
        $history = scriptTwinfield([soapResponse('<salesinvoice result="1"/>')]);
        $plugin->getSync()->pushOrder($invoiceOrder);

        return count($history) === 0 ?: 'it sent ' . count($history) . ' requests';
    });

    check('a rejection records the field Twinfield objected to', function() {
        global $plugin;
        $variant = makeProduct('TW-FAIL-' . StringHelper::randomString(4), 12.00)->getVariants()[0];
        $order = makeOrder([['variant' => $variant, 'qty' => 1]]);

        scriptTwinfield([soapResponse(
            '<salesinvoice result="0"><header><customer result="0" msg="Unknown dimension" msgtype="error">1000</customer></header></salesinvoice>'
        )]);

        $document = $plugin->getSync()->pushOrder($order);

        return ($document->isFailed() && str_contains((string)$document->lastError, 'Unknown dimension'))
            ?: $document->status . ' / ' . $document->lastError;
    });

    check('a failed document counts its attempts', function() {
        global $plugin;
        $variant = makeProduct('TW-ATT-' . StringHelper::randomString(4), 12.00)->getVariants()[0];
        $order = makeOrder([['variant' => $variant, 'qty' => 1]]);

        scriptTwinfield([
            soapResponse('<salesinvoice result="0"><header><customer result="0" msg="Nope" msgtype="error">x</customer></header></salesinvoice>'),
            soapResponse('<salesinvoice result="0"><header><customer result="0" msg="Nope" msgtype="error">x</customer></header></salesinvoice>'),
        ]);

        $plugin->getSync()->pushOrder($order);
        $document = $plugin->getSync()->getDocument($order->id);
        $plugin->getSync()->push($document);

        return $plugin->getSync()->getDocument($order->id)->attempts === 2
            ?: $plugin->getSync()->getDocument($order->id)->attempts . ' attempts';
    });

    check('a journal post records the transaction Twinfield created', function() {
        global $plugin;
        settings(['mode' => Settings::MODE_TRANSACTION]);

        $variant = makeProduct('TW-TRX-' . StringHelper::randomString(4), 30.00)->getVariants()[0];
        $order = makeOrder([['variant' => $variant, 'qty' => 1]]);

        scriptTwinfield([soapResponse(
            '<transaction result="1" location="temporary"><header><code>VRK</code><number>202600042</number><invoicenumber>' . $order->reference . '</invoicenumber></header></transaction>'
        )]);

        $document = $plugin->getSync()->pushOrder($order);
        settings(['mode' => Settings::MODE_SALES_INVOICE]);

        return ($document->transactionCode === 'VRK' && $document->transactionNumber === '202600042' && $document->hasFinancials())
            ?: $document->transactionCode . ' ' . $document->transactionNumber . ' / ' . $document->lastError;
    });

    check('a concept invoice honestly reports having no financial transaction', function() use ($invoiceOrder) {
        global $plugin;
        $document = $plugin->getSync()->getDocument($invoiceOrder->id);

        return !$document->hasFinancials();
    });

    check('a final invoice records the financials block', function() {
        global $plugin;
        settings(['invoiceStatus' => Settings::INVOICE_STATUS_FINAL]);

        $variant = makeProduct('TW-FIN-' . StringHelper::randomString(4), 30.00)->getVariants()[0];
        $order = makeOrder([['variant' => $variant, 'qty' => 1]]);

        scriptTwinfield([soapResponse(
            '<salesinvoice result="1"><header><invoicenumber>2026009</invoicenumber></header>'
            . '<financials><code>VRK</code><number>202600099</number></financials></salesinvoice>'
        )]);

        $document = $plugin->getSync()->pushOrder($order);

        return ($document->transactionCode === 'VRK' && $document->transactionNumber === '202600099')
            ?: $document->transactionCode . ' ' . $document->transactionNumber;
    });

    check('two refunds produce two credit notes, and a repeated one does not', function() {
        global $plugin;
        settings(['invoiceStatus' => Settings::INVOICE_STATUS_CONCEPT]);

        $variant = makeProduct('TW-REF-' . StringHelper::randomString(4), 100.00)->getVariants()[0];
        $order = makeOrder([['variant' => $variant, 'qty' => 1]]);

        $refund = new craft\commerce\models\Transaction();
        $refund->orderId = $order->id;
        $refund->amount = 25.00;
        $refund->hash = 'refund-hash-a';

        $second = new craft\commerce\models\Transaction();
        $second->orderId = $order->id;
        $second->amount = 30.00;
        $second->hash = 'refund-hash-b';

        scriptTwinfield([
            soapResponse('<salesinvoice result="1"><header><invoicenumber>3001</invoicenumber></header></salesinvoice>'),
            soapResponse('<salesinvoice result="1"><header><invoicenumber>3002</invoicenumber></header></salesinvoice>'),
            soapResponse('<salesinvoice result="1"><header><invoicenumber>3003</invoicenumber></header></salesinvoice>'),
        ]);

        $plugin->getSync()->pushRefund($order, $refund);
        $plugin->getSync()->pushRefund($order, $second);
        $plugin->getSync()->pushRefund($order, $refund);

        $credits = array_filter(
            $plugin->getSync()->getDocumentsForOrder($order->id),
            static fn(Document $d) => $d->isCreditNote(),
        );

        return count($credits) === 2 ?: count($credits) . ' credit notes';
    });

    check('only a successful refund transaction triggers a credit note, for its own amount', function() {
        global $plugin;
        // The trigger once read the refund event's `transaction`, which is the parent purchase:
        // full purchase amount, the parent's hash, and a credit note for a declined refund too.
        settings(['invoiceStatus' => Settings::INVOICE_STATUS_CONCEPT, 'queuePush' => false, 'creditNotesEnabled' => true]);

        $variant = makeProduct('TW-REFEV-' . StringHelper::randomString(4), 100.00)->getVariants()[0];
        $order = makeOrder([['variant' => $variant, 'qty' => 1]]);

        $make = static function(string $type, string $status, float $amount, string $hash) use ($order) {
            $t = new craft\commerce\models\Transaction();
            $t->orderId = $order->id;
            $t->type = $type;
            $t->status = $status;
            $t->amount = $amount;
            $t->hash = $hash;

            return $t;
        };

        scriptTwinfield([
            soapResponse('<salesinvoice result="1"><header><invoicenumber>3101</invoicenumber></header></salesinvoice>'),
        ]);

        $transactions = Commerce::getInstance()->getTransactions();
        foreach ([
            $make('purchase', 'success', 100.00, 'ev-purchase'),
            $make('refund', 'failed', 40.00, 'ev-refund-failed'),
            $make('refund', 'success', 40.00, 'ev-refund-ok'),
        ] as $t) {
            $transactions->trigger(
                craft\commerce\services\Transactions::EVENT_AFTER_SAVE_TRANSACTION,
                new craft\commerce\events\TransactionEvent(['transaction' => $t]),
            );
        }

        $credits = array_values(array_filter(
            $plugin->getSync()->getDocumentsForOrder($order->id),
            static fn(Document $d) => $d->isCreditNote(),
        ));

        if (count($credits) !== 1) {
            return count($credits) . ' credit notes';
        }

        return ($credits[0]->sourceKey === 'refund:ev-refund-ok' && Amounts::equal(abs((float)$credits[0]->valueTotal), 40.00))
            ?: $credits[0]->sourceKey . ' ' . $credits[0]->valueTotal;
    });

    check('an unconfigured install records nothing on order completion', function() {
        global $plugin;
        // A fresh install must not fill the queue with jobs that cannot succeed.
        settings(['trigger' => Settings::TRIGGER_COMPLETE, 'office' => '']);

        $variant = makeProduct('TW-UNCONF-' . StringHelper::randomString(4), 10.00)->getVariants()[0];
        $order = makeOrder([['variant' => $variant, 'qty' => 1]]);

        $result = $plugin->getSync()->getDocument($order->id) === null;
        settings(['office' => '001', 'trigger' => Settings::TRIGGER_MANUAL]);

        return $result ?: 'a document was recorded with no office configured';
    });

    check('completing an order posts it when the trigger says so', function() {
        global $plugin;
        settings(['trigger' => Settings::TRIGGER_COMPLETE, 'queuePush' => false]);

        scriptTwinfield([soapResponse('<salesinvoice result="1"><header><invoicenumber>4001</invoicenumber></header></salesinvoice>')]);

        $variant = makeProduct('TW-TRIG-' . StringHelper::randomString(4), 10.00)->getVariants()[0];
        $order = makeOrder([['variant' => $variant, 'qty' => 1]]);

        $document = $plugin->getSync()->getDocument($order->id);
        settings(['trigger' => Settings::TRIGGER_MANUAL]);

        return ($document !== null && $document->isSent()) ?: 'document is ' . ($document?->status ?? 'absent');
    });

    check('a Twinfield outage during checkout does not throw into the order', function() {
        global $plugin;
        settings(['trigger' => Settings::TRIGGER_COMPLETE, 'maxAttempts' => 1]);

        scriptTwinfield([new GuzzleResponse(503, [], 'down')]);

        $variant = makeProduct('TW-OUT-' . StringHelper::randomString(4), 10.00)->getVariants()[0];
        $order = makeOrder([['variant' => $variant, 'qty' => 1]]);

        $document = $plugin->getSync()->getDocument($order->id);
        settings(['trigger' => Settings::TRIGGER_MANUAL, 'maxAttempts' => 2]);

        // The order completed; the document is parked as failed for a retry.
        return ($order->isCompleted && $document?->isFailed()) ?: 'document is ' . ($document?->status ?? 'absent');
    });

    check('a failed document is offered for retry', function() {
        global $plugin;

        return count($plugin->getSync()->findRetryable(50)) > 0;
    });

    // ---------------------------------------------------------------------
    section('Reconciliation');

    check('nothing is reconciled while documents are provisional', function() {
        global $plugin;
        settings(['reconcileEnabled' => true, 'invoiceStatus' => Settings::INVOICE_STATUS_CONCEPT]);
        $result = $plugin->getReconcile()->run();
        settings(['invoiceStatus' => Settings::INVOICE_STATUS_FINAL]);

        return $result['checked'] === 0 ?: json_encode($result);
    });

    $reconcileOrder = null;
    $reconcileDocument = null;

    check('a booked document is posted and reconcilable', function() use (&$reconcileOrder, &$reconcileDocument) {
        global $plugin;
        settings(['invoiceStatus' => Settings::INVOICE_STATUS_FINAL, 'reconcileEnabled' => true]);

        $variant = makeProduct('TW-REC-' . StringHelper::randomString(4), 60.00)->getVariants()[0];
        $reconcileOrder = makeOrder([['variant' => $variant, 'qty' => 1]]);

        scriptTwinfield([soapResponse(
            '<salesinvoice result="1"><header><invoicenumber>5001</invoicenumber></header>'
            . '<financials><code>VRK</code><number>202600501</number></financials></salesinvoice>'
        )]);

        $reconcileDocument = $plugin->getSync()->pushOrder($reconcileOrder);

        return $reconcileDocument->hasFinancials() ?: 'no financials recorded';
    });

    check('an open value is read off the total line', function() use (&$reconcileDocument) {
        global $plugin;
        scriptTwinfield([soapResponse(
            '<transaction result="1"><header><code>VRK</code><number>202600501</number></header><lines>'
            . '<line type="total" id="1"><dim1>1300</dim1><value>60.00</value><openvalue>60.00</openvalue><matchstatus>available</matchstatus></line>'
            . '</lines></transaction>'
        )]);

        $paid = $plugin->getReconcile()->check($reconcileDocument);
        $fresh = $plugin->getSync()->getDocumentById($reconcileDocument->id);

        return (!$paid && Amounts::equal((float)$fresh->openValue, 60.00) && $fresh->matchStatus === 'available')
            ?: 'open=' . $fresh->openValue . ' status=' . $fresh->matchStatus;
    });

    check('the documented <valueopen> spelling is read too', function() use (&$reconcileDocument) {
        global $plugin;
        // Twinfield's documentation says <valueopen>; live responses send <openvalue>. Reading
        // only the documented one makes every invoice look permanently unpaid.
        scriptTwinfield([soapResponse(
            '<transaction result="1"><header><code>VRK</code><number>202600501</number></header><lines>'
            . '<line type="total" id="1"><dim1>1300</dim1><value>60.00</value><valueopen>15.00</valueopen></line>'
            . '</lines></transaction>'
        )]);

        $plugin->getReconcile()->check($plugin->getSync()->getDocumentById($reconcileDocument->id));
        $fresh = $plugin->getSync()->getDocumentById($reconcileDocument->id);

        return Amounts::equal((float)$fresh->openValue, 15.00) ?: 'open=' . $fresh->openValue;
    });

    check('a zero open value marks the document paid', function() use (&$reconcileDocument) {
        global $plugin;
        settings(['markOrderPaid' => false, 'paidOrderStatusHandle' => '']);

        scriptTwinfield([soapResponse(
            '<transaction result="1"><header><code>VRK</code><number>202600501</number></header><lines>'
            . '<line type="total" id="1"><dim1>1300</dim1><value>60.00</value><openvalue>0.00</openvalue><matchstatus>matched</matchstatus></line>'
            . '</lines></transaction>'
        )]);

        $paid = $plugin->getReconcile()->check($plugin->getSync()->getDocumentById($reconcileDocument->id));
        $fresh = $plugin->getSync()->getDocumentById($reconcileDocument->id);

        return ($paid && $fresh->isPaid()) ?: 'paid=' . var_export($paid, true) . ' datePaid=' . var_export($fresh->datePaid, true);
    });

    check('a paid document is not reported paid a second time', function() use (&$reconcileDocument) {
        global $plugin;
        scriptTwinfield([soapResponse(
            '<transaction result="1"><header><code>VRK</code><number>202600501</number></header><lines>'
            . '<line type="total" id="1"><dim1>1300</dim1><value>60.00</value><openvalue>0.00</openvalue></line>'
            . '</lines></transaction>'
        )]);

        return $plugin->getReconcile()->check($plugin->getSync()->getDocumentById($reconcileDocument->id)) === false;
    });

    check('a paid document drops out of the open list', function() {
        global $plugin;

        foreach ($plugin->getReconcile()->findOpen(100) as $document) {
            if ($document->isPaid()) {
                return 'a paid document is still listed as open';
            }
        }

        return true;
    });

    check('a payment recorded from Twinfield carries Commerce\'s own currency amounts', function() {
        global $plugin;
        // The payment once set amount and paymentAmount both to the store-currency balance,
        // which is wrong for any order paid in another currency.
        settings(['markOrderPaid' => true, 'paidOrderStatusHandle' => '']);

        $variant = makeProduct('TW-PAY-' . StringHelper::randomString(4), 45.00)->getVariants()[0];
        $order = makeOrder([['variant' => $variant, 'qty' => 1]]);

        // Commerce will not record a transaction on an order with no gateway (an order taken on
        // account, which Twinsies logs instead). Give this one a manual gateway, as a shop that
        // invoices would have.
        $gateway = null;
        foreach (Commerce::getInstance()->getGateways()->getAllGateways() as $candidate) {
            if ($candidate instanceof craft\commerce\gateways\Manual) {
                $gateway = $candidate;
                break;
            }
        }
        if ($gateway === null) {
            return 'no manual gateway in the harness';
        }
        Craft::$app->getDb()->createCommand()->update('{{%commerce_orders}}', ['gatewayId' => $gateway->id], ['id' => $order->id])->execute();
        $order = Order::find()->id($order->id)->status(null)->one();

        scriptTwinfield([
            soapResponse('<salesinvoice result="1"><header><invoicenumber>5101</invoicenumber></header>'
                . '<financials><code>VRK</code><number>202600601</number></financials></salesinvoice>'),
            soapResponse('<transaction result="1"><header><code>VRK</code><number>202600601</number></header><lines>'
                . '<line type="total" id="1"><dim1>1300</dim1><value>45.00</value><openvalue>0.00</openvalue><matchstatus>matched</matchstatus></line>'
                . '</lines></transaction>'),
        ]);

        $document = $plugin->getSync()->pushOrder($order);
        $plugin->getReconcile()->check($plugin->getSync()->getDocumentById($document->id));
        settings(['markOrderPaid' => false]);

        $payments = array_values(array_filter(
            Commerce::getInstance()->getTransactions()->getAllTransactionsByOrderId($order->id),
            static fn($t) => $t->type === 'purchase',
        ));

        if (!$payments) {
            $entry = $plugin->getLog()->getEntries(['action' => 'reconcile.warning', 'orderId' => $order->id], 1)[0] ?? null;

            return 'no payment recorded' . ($entry ? ': ' . $entry->summary : '');
        }

        $t = $payments[0];
        $expected = round((float)$t->amount * (float)$t->paymentRate, 2);

        return ($t->status === 'success' && Amounts::equal((float)$t->amount, 45.00) && Amounts::equal((float)$t->paymentAmount, $expected))
            ?: "status={$t->status} amount={$t->amount} paymentAmount={$t->paymentAmount} rate={$t->paymentRate}";
    });

    check('a transaction with no total line is an error, not a silent pass', function() use (&$reconcileDocument) {
        global $plugin;
        scriptTwinfield([soapResponse(
            '<transaction result="1"><header><code>VRK</code><number>202600501</number></header><lines>'
            . '<line type="detail" id="1"><dim1>8000</dim1><value>60.00</value></line></lines></transaction>'
        )]);

        try {
            $plugin->getReconcile()->check($plugin->getSync()->getDocumentById($reconcileDocument->id));

            return 'no exception';
        } catch (RuntimeException $e) {
            return str_contains($e->getMessage(), 'total line') ?: $e->getMessage();
        }
    });

    check('a total line with no open value is an error, not a payment', function() use (&$reconcileDocument) {
        global $plugin;
        // Reading a missing open value as zero recorded a Commerce payment nobody made.
        scriptTwinfield([soapResponse(
            '<transaction result="1"><header><code>VRK</code><number>202600501</number></header><lines>'
            . '<line type="total" id="1"><dim1>1300</dim1><value>60.00</value><matchstatus>available</matchstatus></line>'
            . '</lines></transaction>'
        )]);

        try {
            $plugin->getReconcile()->check($plugin->getSync()->getDocumentById($reconcileDocument->id));

            return 'no exception';
        } catch (RuntimeException $e) {
            return str_contains($e->getMessage(), 'open value') ?: $e->getMessage();
        }
    });

    check('a matched total line with no open value reads as settled', function() use (&$reconcileDocument) {
        global $plugin;
        scriptTwinfield([soapResponse(
            '<transaction result="1"><header><code>VRK</code><number>202600501</number></header><lines>'
            . '<line type="total" id="1"><dim1>1300</dim1><value>60.00</value><matchstatus>matched</matchstatus></line>'
            . '</lines></transaction>'
        )]);

        $plugin->getReconcile()->check($plugin->getSync()->getDocumentById($reconcileDocument->id));
        $fresh = $plugin->getSync()->getDocumentById($reconcileDocument->id);

        return Amounts::equal((float)$fresh->openValue, 0.0) ?: 'open=' . var_export($fresh->openValue, true);
    });

    settings(['reconcileEnabled' => false, 'invoiceStatus' => Settings::INVOICE_STATUS_CONCEPT]);

    // ---------------------------------------------------------------------
    section('The log');

    check('a request and its response are both kept', function() {
        global $plugin;
        scriptTwinfield([soapResponse('<offices><office name="Fixture BV">001</office></offices>')]);
        $plugin->getApi()->listing('offices', false);

        $entry = $plugin->getLog()->getEntries(['action' => 'list.offices'], 1)[0] ?? null;
        $full = $entry ? $plugin->getLog()->getEntryById($entry->id) : null;

        return ($full?->request && $full->response) ?: 'nothing was logged';
    });

    check('a logged document carries no access token to begin with', function() {
        global $plugin;
        // `process()` logs the Twinfield document rather than the envelope: it is what a merchant
        // needs to see, and it is the half that has no credentials in it.
        $entry = $plugin->getLog()->getEntries(['action' => 'list.offices'], 1)[0] ?? null;
        $full = $entry ? $plugin->getLog()->getEntryById($entry->id) : null;

        return !str_contains((string)$full?->request, 'fixture-access-token') ?: 'the token is in the log';
    });

    check('the finder envelope is logged with its access token redacted', function() {
        global $plugin;
        // A CP user allowed to read the log is not necessarily someone who should be able to lift
        // a token for the whole administration out of it.
        $body = '<?xml version="1.0" encoding="utf-8"?>'
            . '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body>'
            . '<SearchResponse xmlns="http://www.twinfield.com/"><data><TotalRows>0</TotalRows></data>'
            . '</SearchResponse></soap:Body></soap:Envelope>';

        scriptTwinfield([new GuzzleResponse(200, ['Content-Type' => 'text/xml'], $body)]);
        $plugin->getApi()->search('VAT', '*');

        $entry = $plugin->getLog()->getEntries(['action' => 'finder.VAT'], 1)[0] ?? null;
        $full = $entry ? $plugin->getLog()->getEntryById($entry->id) : null;

        return (!str_contains((string)$full?->request, 'fixture-access-token')
            && str_contains((string)$full?->request, '[redacted]'))
            ?: 'the token is still in the log';
    });

    check('a rejection is logged at error level', function() {
        global $plugin;
        $errors = $plugin->getLog()->getEntries(['level' => 'error'], 5);

        return count($errors) > 0;
    });

    check('payload logging can be switched off', function() {
        global $plugin;
        settings(['logPayloads' => false]);

        scriptTwinfield([soapResponse('<offices><office name="Fixture BV">001</office></offices>')]);
        $plugin->getApi()->listing('offices', false);

        $entry = $plugin->getLog()->getEntries(['action' => 'list.offices'], 1)[0] ?? null;
        $full = $entry ? $plugin->getLog()->getEntryById($entry->id) : null;
        settings(['logPayloads' => true]);

        return $full?->request === null ?: 'a payload was stored anyway';
    });

    check('pruning removes only entries past the retention', function() {
        global $plugin;
        $before = $plugin->getLog()->countEntries();
        $plugin->getLog()->prune(3650);

        return $plugin->getLog()->countEntries() === $before;
    });

    check('a failure to log does not take down the request it described', function() {
        global $plugin;
        settings(['loggingEnabled' => false]);
        scriptTwinfield([soapResponse('<offices><office name="Fixture BV">001</office></offices>')]);

        $offices = $plugin->getApi()->listing('offices', false);
        settings(['loggingEnabled' => true]);

        return Xml::succeeded($offices);
    });

    // ---------------------------------------------------------------------
    section('Twig');

    check('the variable finds an order’s document', function() use ($invoiceOrder) {
        $variable = new justinholtweb\twinsies\twig\TwinsiesVariable();

        return $variable->document($invoiceOrder)?->orderId === $invoiceOrder->id;
    });

    check('the reference is only offered once the document is posted', function() use ($invoiceOrder) {
        $variable = new justinholtweb\twinsies\twig\TwinsiesVariable();

        return $variable->reference($invoiceOrder) !== null;
    });

    check('an order with nothing posted answers null rather than throwing', function() {
        $variable = new justinholtweb\twinsies\twig\TwinsiesVariable();

        return $variable->document(0) === null && $variable->documents(null) === [] && !$variable->isPaid(null);
    });

    // ---------------------------------------------------------------------
    section('Metadata');

    check('offices are asked for without a company header', function() {
        global $plugin;
        $plugin->getMeta()->flush();
        $history = scriptTwinfield([soapResponse('<offices><office name="Fixture BV" shortname="FIX">001</office><office name="Other BV">002</office></offices>')]);

        $offices = $plugin->getMeta()->getOffices(true);
        $envelope = Xml::parse(sentBody($history));

        return ($offices === ['001' => 'Fixture BV', '002' => 'Other BV']
            && $envelope->getElementsByTagNameNS(Api::NS_TWINFIELD, 'CompanyCode')->length === 0)
            ?: json_encode($offices);
    });

    check('a catalogue is cached rather than fetched twice', function() {
        global $plugin;
        $history = scriptTwinfield([soapResponse('<offices><office name="Fixture BV">001</office></offices>')]);

        $plugin->getMeta()->getOffices(true);
        $plugin->getMeta()->getOffices();

        return count($history) === 1 ?: count($history) . ' requests';
    });

    check('an unreachable catalogue degrades to an empty list, not a 500', function() {
        global $plugin;
        // A plugin whose settings page dies because the accounting system is down is a plugin
        // that cannot be turned off.
        $plugin->getMeta()->flush();
        settings(['maxAttempts' => 1]);
        scriptTwinfield([new GuzzleResponse(500, [], 'boom')]);

        $result = $plugin->getMeta()->safely('vatcodes', true) === [];
        settings(['maxAttempts' => 2]);

        return $result;
    });

    check('a connection test names the office it verified', function() {
        global $plugin;
        $plugin->getMeta()->flush();
        scriptTwinfield([soapResponse('<offices><office name="Fixture BV">001</office></offices>')]);

        $result = $plugin->getMeta()->testConnection();

        return ($result['ok'] && str_contains($result['message'], 'Fixture BV')) ?: json_encode($result);
    });

    check('a test against an office this user cannot reach fails clearly', function() {
        global $plugin;
        $plugin->getMeta()->flush();
        settings(['office' => '999']);
        scriptTwinfield([soapResponse('<offices><office name="Fixture BV">001</office></offices>')]);

        $result = $plugin->getMeta()->testConnection();
        settings(['office' => '001']);

        return (!$result['ok'] && str_contains($result['message'], '999')) ?: json_encode($result);
    });

    check('a stored code missing from the list stays selectable', function() {
        global $plugin;
        // A select whose value is not among its options saves back as blank — a daybook or ledger
        // account lost because a catalogue came back short, or not at all.
        $meta = $plugin->getMeta();
        $options = $meta->toOptions(['VRK' => 'Verkoop'], '—');

        $kept = $meta->withValue($options, 'MEMO');
        $same = $meta->withValue($options, 'VRK');
        $blank = $meta->withValue($options, '');

        return (count($kept) === 3 && end($kept)['value'] === 'MEMO' && count($same) === 2 && count($blank) === 2)
            ?: json_encode($kept);
    });

    check('options are shaped for Craft’s form macros', function() {
        global $plugin;
        $options = $plugin->getMeta()->toOptions(['001' => 'Fixture BV'], '—');

        return ($options[0] === ['label' => '—', 'value' => '']
            && $options[1] === ['label' => 'Fixture BV (001)', 'value' => '001'])
            ?: json_encode($options);
    });
} finally {
    section('Cleanup');

    $elements = Craft::$app->getElements();

    foreach (array_reverse($createdOrders) as $fixtureOrder) {
        try {
            $elements->deleteElement($fixtureOrder, true);
        } catch (Throwable $e) {
            echo "  ! could not delete order {$fixtureOrder->id}: {$e->getMessage()}\n";
        }
    }

    foreach ($createdProducts as $fixtureProduct) {
        try {
            $elements->deleteElement($fixtureProduct, true);
        } catch (Throwable $e) {
            echo "  ! could not delete product {$fixtureProduct->id}: {$e->getMessage()}\n";
        }
    }

    foreach (['documents', 'customers', 'articles', 'auth', 'log'] as $table) {
        try {
            Craft::$app->getDb()->createCommand()->delete('{{%twinsies_' . $table . '}}')->execute();
        } catch (Throwable $e) {
            echo "  ! could not clear twinsies_{$table}: {$e->getMessage()}\n";
        }
    }

    try {
        $plugin->getMeta()->flush();
        $plugin->getApi()->setClient(null);
        $plugin->getAuth()->setClient(null);
    } catch (Throwable $e) {
        echo "  ! could not reset the clients: {$e->getMessage()}\n";
    }

    try {
        settings($originalSettings);
    } catch (Throwable $e) {
        echo "  ! could not restore settings: {$e->getMessage()}\n";
    }

    echo "  ✓ fixtures removed, settings restored\n";

    echo "\n" . str_repeat('-', 60) . "\n";
    echo "  $passed passed, $failed failed\n";
    echo str_repeat('-', 60) . "\n";
}

exit($failed > 0 ? 1 : 0);
