<?php

namespace justinholtweb\twinsies\services;

use Craft;
use craft\base\Component;
use craft\commerce\elements\Order;
use craft\commerce\models\LineItem;
use justinholtweb\twinsies\helpers\Amounts;
use justinholtweb\twinsies\helpers\Dates;
use justinholtweb\twinsies\helpers\Xml;
use justinholtweb\twinsies\models\BuiltDocument;
use justinholtweb\twinsies\models\Document;
use justinholtweb\twinsies\models\Settings;
use justinholtweb\twinsies\Plugin;

/**
 * Commerce orders as Twinfield documents.
 *
 * **`build()` is the only place an order becomes Twinfield XML.** The push, the control panel's
 * "Preview XML" and `twinsies/sync/preview` all come through here, so what a merchant is shown
 * before posting is byte-for-byte what Twinfield receives. An integration where the preview is a
 * separate rendering is an integration where the preview is eventually a lie.
 *
 * ## Two things that go wrong if you build this the obvious way
 *
 * **Never emit Commerce's tax as its own line.** Twinfield derives VAT from the `vatcode` on each
 * line and books it itself. A tax line as well produces an invoice with the VAT charged twice, and
 * Twinfield accepts it without complaint because it is arithmetically consistent.
 *
 * **Included tax is inside the subtotal.** When a store prices inclusive of VAT, Commerce's line
 * subtotal is the gross figure and the tax adjustment is flagged `included`. Sending that subtotal
 * as `unitspriceexcl` overstates every invoice by the VAT rate.
 */
class Documents extends Component
{
    /**
     * Twinfield truncates a line description at 110 characters; longer is rejected, not trimmed.
     */
    public const MAX_DESCRIPTION = 110;

    /**
     * Twinfield absorbs up to two cents of VAT rounding when `autobalancevat` is on. Beyond that
     * a transaction has to be balanced explicitly or it will not post.
     */
    public const VAT_TOLERANCE = 0.02;

    /**
     * Drift between the lines and the order total that will be booked to a visible "Rounding"
     * line. Anything larger fails the build.
     *
     * That refusal is deliberate. A connector that quietly books an unexplained difference to a
     * revenue account produces books that reconcile and are wrong, and nobody finds out until an
     * accountant does. Failing here costs a support ticket; the alternative costs an audit.
     */
    public const ROUNDING_TOLERANCE = 0.05;

    /**
     * Order-level adjustment types Twinsies knows how to express as a Twinfield line. Anything
     * else is named in the reconciliation error rather than silently dropped.
     */
    private const KNOWN_ADJUSTMENTS = ['tax', 'shipping', 'discount'];

    /**
     * Build the document for an order.
     *
     * @param string $kind {@see Document::KIND_INVOICE} or {@see Document::KIND_CREDIT_NOTE}
     * @param float|null $creditAmount for a credit note, the gross amount being credited. Null
     *                                 credits the whole order.
     * @param bool $dryRun write nothing to Twinfield: for Preview XML. The debtor and any articles
     *                     a push would create are named in the warnings instead.
     * @throws \RuntimeException when the order cannot be expressed as a Twinfield document at all
     */
    public function build(Order $order, string $kind = Document::KIND_INVOICE, ?float $creditAmount = null, bool $dryRun = false): BuiltDocument
    {
        $settings = Plugin::getInstance()->getSettings();
        $warnings = [];
        $articles = [];

        $lines = $this->collectLines($order, $warnings, $articles);

        if ($kind === Document::KIND_CREDIT_NOTE) {
            $lines = $this->creditLines($lines, $order, $creditAmount, $warnings);
        }

        if (!$lines) {
            throw new \RuntimeException(Craft::t('twinsies', 'This order has nothing to post: every line came to zero.'));
        }

        // Reconcile against what the customer was actually charged, before either mode gets a
        // chance to render a total nobody can explain.
        if ($kind === Document::KIND_INVOICE) {
            $lines = $this->reconcileAgainstOrder($order, $lines, $warnings);
        }

        // Only now touch Twinfield: every refusal above has had its chance, so a build that is
        // going to fail never leaves a debtor or an article behind in the books.
        if ($dryRun) {
            $customerCode = Plugin::getInstance()->getCustomers()->peekForOrder($order, $warnings);

            foreach ($articles as $article) {
                $warnings[] = Craft::t('twinsies', 'Posting creates article {code} in Twinfield first, if it does not exist yet.', ['code' => $article[0]]);
            }
        } else {
            $this->ensureArticles($articles);
            $customerCode = $this->resolveCustomer($order, $warnings);
        }

        $gross = array_sum(array_map(static fn(array $line) => $line['net'] + $line['tax'], $lines));

        return $settings->isTransactionMode()
            ? $this->buildTransaction($order, $kind, $lines, $customerCode, $gross, $warnings)
            : $this->buildSalesInvoice($order, $kind, $lines, $customerCode, $gross, $warnings);
    }

    // Sales invoice mode
    // -------------------------------------------------------------------------

    /**
     * @param array<int, array<string, mixed>> $lines
     * @param string[] $warnings
     */
    private function buildSalesInvoice(
        Order $order,
        string $kind,
        array $lines,
        ?string $customerCode,
        float $gross,
        array $warnings,
    ): BuiltDocument {
        $settings = Plugin::getInstance()->getSettings();
        $isCredit = $kind === Document::KIND_CREDIT_NOTE;
        $invoiceType = $isCredit ? $settings->getCreditInvoiceType() : $settings->invoiceType;

        if ($customerCode === null) {
            throw new \RuntimeException(Craft::t('twinsies', 'No Twinfield debtor could be established for this order, and a sales invoice must name one.'));
        }

        $doc = Xml::document('salesinvoice', ['raisewarning' => 'false']);
        $root = $doc->documentElement;

        $ordered = $order->dateOrdered ?? $order->dateCreated ?? new \DateTime();

        $header = Xml::container($root, 'header');
        Xml::append($header, 'office', $settings->getOffice());
        Xml::append($header, 'invoicetype', $invoiceType);
        Xml::append($header, 'invoicedate', Dates::date($ordered));
        Xml::append($header, 'duedate', Dates::date(Dates::addDays($ordered, max(0, $settings->dueDays))));
        Xml::append($header, 'bank', $settings->bank);
        Xml::append($header, 'customer', $customerCode);

        if ($settings->sendPeriod) {
            Xml::append($header, 'period', Dates::period($ordered));
        }

        Xml::append($header, 'currency', $this->currency($order));

        if ($settings->sendOwnInvoiceNumber && !$isCredit) {
            // Only digits survive: Twinfield's invoice number is an integer, and an order
            // reference like "2026-0042" posts as a rejected document rather than a numbered one.
            $number = preg_replace('/\D/', '', (string)$order->reference);

            if ($number !== '' && $number !== null) {
                Xml::append($header, 'invoicenumber', ltrim($number, '0') ?: $number);
            } else {
                $warnings[] = Craft::t('twinsies', 'The order reference has no digits in it, so Twinfield numbered this invoice itself.');
            }
        }

        Xml::append($header, 'status', $settings->invoiceStatus);
        Xml::append($header, 'paymentmethod', $settings->paymentMethod);
        Xml::append($header, 'headertext', $this->renderTemplate($settings->headerText, $order));
        Xml::append($header, 'footertext', $this->renderTemplate($settings->footerText, $order));

        $linesEl = Xml::container($root, 'lines');
        $id = 1;
        $negate = $isCredit && $settings->creditNeedsNegativeAmounts();

        foreach ($lines as $line) {
            $lineEl = Xml::container($linesEl, 'line', ['id' => (string)$id++]);

            Xml::append($lineEl, 'article', $line['article']);
            Xml::append($lineEl, 'subarticle', $line['subarticle']);
            Xml::append($lineEl, 'quantity', $line['quantity']);
            Xml::append($lineEl, 'units', '1');

            $unitPrice = $line['quantity'] > 0 ? $line['net'] / $line['quantity'] : $line['net'];
            Xml::append($lineEl, 'unitspriceexcl', Amounts::precise($negate ? -$unitPrice : $unitPrice));

            Xml::append($lineEl, 'vatcode', $line['vatCode']);
            // Order-level discounts are already in the line amounts. Letting Twinfield apply an
            // article's own discount rule on top would deduct them twice.
            Xml::append($lineEl, 'allowdiscountorpremium', 'false');
            Xml::append($lineEl, 'description', $this->truncate($line['description']));
            Xml::append($lineEl, 'freetext1', $line['freetext1']);
            Xml::append($lineEl, 'dim1', $line['revenueGl']);

            if ($line['vatCode'] === null) {
                $warnings[] = Craft::t('twinsies', 'Line “{line}” has no Twinfield VAT code; Twinfield will fall back to the article’s own.', [
                    'line' => $this->truncate($line['description'], 40),
                ]);
            }
        }

        return new BuiltDocument(
            xml: Xml::toString($doc),
            kind: $kind,
            mode: Settings::MODE_SALES_INVOICE,
            office: $settings->getOffice(),
            bookCode: $invoiceType,
            customerCode: $customerCode,
            currency: $this->currency($order),
            total: Amounts::round($isCredit && $negate ? -$gross : $gross),
            warnings: array_values(array_unique($warnings)),
        );
    }

    // Transaction (journal) mode
    // -------------------------------------------------------------------------

    /**
     * A sales transaction: one total line against the debtor, one detail line per revenue account
     * and VAT code, and optionally explicit VAT lines.
     *
     * Twinfield has no negative amounts on a transaction line. A credit is the same amounts on the
     * other side, which is why the sides are chosen once, here, rather than by negating values.
     *
     * @param array<int, array<string, mixed>> $lines
     * @param string[] $warnings
     */
    private function buildTransaction(
        Order $order,
        string $kind,
        array $lines,
        ?string $customerCode,
        float $gross,
        array $warnings,
    ): BuiltDocument {
        $settings = Plugin::getInstance()->getSettings();
        $isCredit = $kind === Document::KIND_CREDIT_NOTE;
        $daybook = $isCredit ? $settings->getCreditDaybook() : $settings->daybook;

        if (trim($settings->debtorGl) === '') {
            throw new \RuntimeException(Craft::t('twinsies', 'No debtor ledger account is configured, and a sales transaction needs one for its total line.'));
        }

        if ($customerCode === null) {
            throw new \RuntimeException(Craft::t('twinsies', 'No Twinfield debtor could be established for this order, and a sales transaction must name one.'));
        }

        // An invoice debits the debtor and credits revenue; a credit note is the mirror image.
        $debtorSide = $isCredit ? Amounts::CREDIT : Amounts::DEBIT;
        $revenueSide = $isCredit ? Amounts::DEBIT : Amounts::CREDIT;

        $ordered = $order->dateOrdered ?? $order->dateCreated ?? new \DateTime();

        $doc = Xml::document('transaction', [
            'destiny' => $settings->destiny,
            'autobalancevat' => $settings->autoBalanceVat ? 'true' : 'false',
            'raisewarning' => 'false',
        ]);
        $root = $doc->documentElement;

        $header = Xml::container($root, 'header');
        Xml::append($header, 'office', $settings->getOffice());
        Xml::append($header, 'code', $daybook);
        Xml::append($header, 'currency', $this->currency($order));
        Xml::append($header, 'date', Dates::date($ordered));

        if ($settings->sendPeriod) {
            Xml::append($header, 'period', Dates::period($ordered));
        }

        // The order reference is the invoice number. It is also the handle a merchant uses to find
        // the posting again from Twinfield's side, so it goes on even when Craft numbered it.
        Xml::append($header, 'invoicenumber', mb_substr((string)$order->reference, 0, 40));
        Xml::append($header, 'duedate', Dates::date(Dates::addDays($ordered, max(0, $settings->dueDays))));
        Xml::append($header, 'freetext1', mb_substr((string)$order->reference, 0, 36));

        $linesEl = Xml::container($root, 'lines');
        $id = 1;

        // Detail lines are grouped: one per (revenue account, VAT code) pair. A hundred-line order
        // does not need a hundred journal lines, and Twinfield's fair use guidance is 25 children.
        $buckets = $this->groupForTransaction($lines);
        $detailIds = [];
        $netTotal = 0.0;
        $taxTotal = 0.0;

        foreach ($buckets as $bucket) {
            $lineId = $id++;
            $detailIds[] = ['id' => $lineId, 'bucket' => $bucket];

            $lineEl = Xml::container($linesEl, 'line', ['type' => 'detail', 'id' => (string)$lineId]);

            [$value, $side] = Amounts::signed($bucket['net'], $revenueSide);

            Xml::append($lineEl, 'dim1', $bucket['revenueGl'] ?: $settings->defaultRevenueGl);
            Xml::append($lineEl, 'debitcredit', $side);
            Xml::append($lineEl, 'value', $value);
            Xml::append($lineEl, 'vatcode', $bucket['vatCode']);

            // Handing Twinfield the VAT Commerce actually charged, rather than letting it
            // recompute from the rate, is what keeps the two systems to the cent on orders where
            // a discount moved the taxable base.
            if (!Amounts::equal($bucket['tax'], 0.0)) {
                Xml::append($lineEl, 'vatvalue', Amounts::money(abs($bucket['tax'])));
            }

            Xml::append($lineEl, 'description', $this->truncate($bucket['description'], 40));

            $netTotal += $bucket['net'];
            $taxTotal += $bucket['tax'];
        }

        if ($settings->emitVatLines && trim($settings->vatGl) !== '') {
            foreach ($detailIds as $detail) {
                if (Amounts::equal($detail['bucket']['tax'], 0.0)) {
                    continue;
                }

                $lineEl = Xml::container($linesEl, 'line', ['type' => 'vat', 'id' => (string)$id++]);
                [$value, $side] = Amounts::signed($detail['bucket']['tax'], $revenueSide);

                Xml::append($lineEl, 'dim1', $settings->vatGl);
                Xml::append($lineEl, 'debitcredit', $side);
                Xml::append($lineEl, 'value', $value);
                Xml::append($lineEl, 'vatcode', $detail['bucket']['vatCode']);
                Xml::append($lineEl, 'vatturnover', Amounts::money(abs($detail['bucket']['net'])));
                // `baseline` points at the detail line this VAT belongs to. Without it Twinfield
                // cannot tie the VAT to a turnover figure and the VAT return comes out short.
                Xml::append($lineEl, 'baseline', (string)$detail['id']);
            }
        }

        $expected = Amounts::round($netTotal + $taxTotal);
        $drift = Amounts::round($gross - $expected);

        if (!Amounts::equal($drift, 0.0) && abs($drift) > self::ROUNDING_TOLERANCE) {
            // Grouping lines onto one journal line per account and VAT code rounds; it cannot
            // move money. A difference this size means something upstream is wrong, and a journal
            // that balances by way of an unexplained revenue posting is worse than no journal.
            throw new \RuntimeException(Craft::t('twinsies', 'The journal lines come to {lines} but the total line would be {total}. Nothing was posted.', [
                'lines' => Amounts::money($expected),
                'total' => Amounts::money($gross),
            ]));
        }

        if (!Amounts::equal($drift, 0.0) && abs($drift) > self::VAT_TOLERANCE) {
            $warnings[] = Craft::t('twinsies', 'A {amount} rounding difference was booked to the default revenue account.', [
                'amount' => Amounts::money($drift),
            ]);

            $lineEl = Xml::container($linesEl, 'line', ['type' => 'detail', 'id' => (string)$id++]);
            [$value, $side] = Amounts::signed($drift, $revenueSide);

            Xml::append($lineEl, 'dim1', $settings->defaultRevenueGl);
            Xml::append($lineEl, 'debitcredit', $side);
            Xml::append($lineEl, 'value', $value);
            Xml::append($lineEl, 'description', 'Rounding');
        }

        // The total line comes last but describes the whole posting: the gross amount against the
        // debtor control account, with the customer as dim2.
        $totalEl = Xml::container($linesEl, 'line', ['type' => 'total', 'id' => (string)$id]);
        [$totalValue, $totalSide] = Amounts::signed($gross, $debtorSide);

        Xml::append($totalEl, 'dim1', $settings->debtorGl);
        Xml::append($totalEl, 'dim2', $customerCode);
        Xml::append($totalEl, 'debitcredit', $totalSide);
        Xml::append($totalEl, 'value', $totalValue);
        Xml::append($totalEl, 'description', $this->truncate(
            Craft::t('twinsies', 'Order {reference}', ['reference' => $order->reference]),
            40,
        ));
        Xml::append($totalEl, 'invoicenumber', mb_substr((string)$order->reference, 0, 40));

        return new BuiltDocument(
            xml: Xml::toString($doc),
            kind: $kind,
            mode: Settings::MODE_TRANSACTION,
            office: $settings->getOffice(),
            bookCode: $daybook,
            customerCode: $customerCode,
            currency: $this->currency($order),
            total: Amounts::round($gross),
            warnings: array_values(array_unique($warnings)),
        );
    }

    // Lines
    // -------------------------------------------------------------------------

    /**
     * Every billable thing on the order, as neutral buckets both modes can render.
     *
     * @param string[] $warnings
     * @return array<int, array<string, mixed>>
     */
    public function buildLines(Order $order, array &$warnings = []): array
    {
        $articles = [];
        $lines = $this->collectLines($order, $warnings, $articles);
        $this->ensureArticles($articles);

        return $lines;
    }

    /**
     * @param array<int, array{0: string, 1: string, 2: string|null, 3: float}> $articles
     */
    private function ensureArticles(array $articles): void
    {
        $mapping = Plugin::getInstance()->getMapping();

        foreach ($articles as [$code, $name, $vatCode, $unitPrice]) {
            $mapping->ensureArticle($code, $name, $vatCode, $unitPrice);
        }
    }

    /**
     * The lines, and the articles that would have to exist in Twinfield first — collected rather
     * than created, so nothing is written until the build is known to succeed.
     *
     * @param string[] $warnings
     * @param array<int, array{0: string, 1: string, 2: string|null, 3: float}> $articles
     * @return array<int, array<string, mixed>>
     */
    private function collectLines(Order $order, array &$warnings, array &$articles): array
    {
        $mapping = Plugin::getInstance()->getMapping();
        $settings = Plugin::getInstance()->getSettings();
        $lines = [];

        foreach ($order->getLineItems() as $lineItem) {
            $tax = $mapping->taxFor($lineItem);

            // Included tax lives *inside* the subtotal. Sending the subtotal as an ex-VAT price
            // overstates the invoice by the VAT rate, on every line, silently.
            $net = Amounts::round(
                (float)$lineItem->getSubtotal()
                + $this->lineAdjustmentTotal($lineItem, 'discount')
                - ($tax['included'] ? $tax['tax'] : 0.0)
            );

            if (Amounts::equal($net, 0.0) && Amounts::equal($tax['tax'], 0.0)) {
                continue;
            }

            $map = $mapping->forLineItem($lineItem);
            $vatCode = $map->vatCode ?: $mapping->vatCodeFor($net, $tax['tax'], $tax['categoryHandle']);

            if ($settings->autoCreateArticles && $map->article) {
                $articles[$map->article] ??= [
                    $map->article,
                    (string)$lineItem->getDescription(),
                    $vatCode,
                    $lineItem->qty > 0 ? $net / $lineItem->qty : $net,
                ];
            }

            $lines[] = [
                'article' => $map->article,
                'subarticle' => $map->subarticle,
                'revenueGl' => $map->revenueGl,
                'vatCode' => $vatCode,
                'description' => $this->lineDescription($lineItem),
                'quantity' => max(1, (int)$lineItem->qty),
                'net' => $net,
                'tax' => $tax['tax'],
                'freetext1' => $settings->sendSkuAsFreetext ? mb_substr((string)$lineItem->getSku(), 0, 36) : null,
            ];
        }

        $lines = array_merge($lines, $this->orderLevelLines($order, $warnings));

        return $lines;
    }

    /**
     * Shipping and order-level discounts, which Commerce keeps as adjustments rather than lines.
     *
     * @param string[] $warnings
     * @return array<int, array<string, mixed>>
     */
    private function orderLevelLines(Order $order, array &$warnings): array
    {
        $mapping = Plugin::getInstance()->getMapping();
        $orderAdjustments = array_filter(
            $order->getAdjustments(),
            static fn($adjustment) => $adjustment->lineItemId === null,
        );

        $lines = [];

        foreach ([
            'shipping' => $mapping->forShipping(),
            'discount' => $mapping->forDiscount(),
        ] as $type => $map) {
            $net = 0.0;

            foreach ($orderAdjustments as $adjustment) {
                if ($adjustment->type === $type && !$adjustment->included) {
                    $net += (float)$adjustment->amount;
                }
            }

            $net = Amounts::round($net);
            $tax = $mapping->taxForAdjustments($orderAdjustments, $type);

            if (Amounts::equal($net, 0.0) && Amounts::equal($tax, 0.0)) {
                continue;
            }

            $lines[] = [
                'article' => $map->article,
                'subarticle' => $map->subarticle,
                'revenueGl' => $map->revenueGl,
                'vatCode' => $map->vatCode ?: $mapping->vatCodeFor($net, $tax),
                'description' => $type === 'shipping'
                    ? Craft::t('twinsies', 'Shipping')
                    : Craft::t('twinsies', 'Discount'),
                'quantity' => 1,
                'net' => $net,
                'tax' => $tax,
                'freetext1' => null,
            ];
        }

        return $lines;
    }

    /**
     * Turn the invoice lines into credit lines.
     *
     * A full credit mirrors the invoice exactly, which keeps the VAT per line correct. A partial
     * credit cannot know which items came back, so the amount is apportioned across the VAT codes
     * on the order in proportion to their net value — the only split that leaves the VAT return
     * right without inventing facts about the return.
     *
     * @param array<int, array<string, mixed>> $lines
     * @param string[] $warnings
     * @return array<int, array<string, mixed>>
     */
    private function creditLines(array $lines, Order $order, ?float $creditAmount, array &$warnings): array
    {
        $gross = array_sum(array_map(static fn(array $line) => $line['net'] + $line['tax'], $lines));

        if ($creditAmount === null || Amounts::equal($creditAmount, $gross)) {
            return $lines;
        }

        if (Amounts::equal($gross, 0.0)) {
            throw new \RuntimeException(Craft::t('twinsies', 'This order has a zero total, so a partial credit cannot be apportioned across it.'));
        }

        $ratio = abs($creditAmount) / abs($gross);

        $warnings[] = Craft::t('twinsies', 'This is a partial credit of {amount} against {total}; it was split across the order’s VAT codes in proportion to their value.', [
            'amount' => Amounts::money(abs($creditAmount)),
            'total' => Amounts::money(abs($gross)),
        ]);

        $credited = [];

        foreach ($lines as $line) {
            $line['net'] = Amounts::round($line['net'] * $ratio);
            $line['tax'] = Amounts::round($line['tax'] * $ratio);
            $line['quantity'] = 1;
            $line['description'] = Craft::t('twinsies', 'Credit: {description}', ['description' => $line['description']]);

            if (Amounts::equal($line['net'], 0.0) && Amounts::equal($line['tax'], 0.0)) {
                continue;
            }

            $credited[] = $line;
        }

        // Apportioning by ratio loses cents. Put them back on the largest line rather than leaving
        // the credit note a few cents short of the refund Commerce actually took.
        $creditedGross = array_sum(array_map(static fn(array $line) => $line['net'] + $line['tax'], $credited));
        $residual = Amounts::round(abs($creditAmount) - $creditedGross);

        if (!Amounts::equal($residual, 0.0) && $credited) {
            $largest = 0;

            foreach ($credited as $index => $line) {
                if (abs($line['net']) > abs($credited[$largest]['net'])) {
                    $largest = $index;
                }
            }

            $credited[$largest]['net'] = Amounts::round($credited[$largest]['net'] + $residual);
        }

        return $credited;
    }

    /**
     * Collapse lines onto one journal line per revenue account and VAT code.
     *
     * @param array<int, array<string, mixed>> $lines
     * @return array<int, array<string, mixed>>
     */
    private function groupForTransaction(array $lines): array
    {
        $buckets = [];

        foreach ($lines as $line) {
            $key = ($line['revenueGl'] ?? '') . '|' . ($line['vatCode'] ?? '');

            if (!isset($buckets[$key])) {
                $buckets[$key] = [
                    'revenueGl' => $line['revenueGl'],
                    'vatCode' => $line['vatCode'],
                    'net' => 0.0,
                    'tax' => 0.0,
                    'description' => $line['description'],
                ];
            }

            $buckets[$key]['net'] = Amounts::round($buckets[$key]['net'] + $line['net']);
            $buckets[$key]['tax'] = Amounts::round($buckets[$key]['tax'] + $line['tax']);
        }

        return array_values($buckets);
    }

    // -------------------------------------------------------------------------

    /**
     * @param string[] $warnings
     */
    private function resolveCustomer(Order $order, array &$warnings): ?string
    {
        try {
            $code = Plugin::getInstance()->getCustomers()->resolveForOrder($order);
        } catch (\Throwable $e) {
            $warnings[] = $e->getMessage();

            return null;
        }

        if ($code === null) {
            $warnings[] = Craft::t('twinsies', 'No Twinfield debtor could be established for this order.');
        }

        return $code;
    }

    /**
     * Make the lines add up to what the customer paid, or refuse to build.
     *
     * @param array<int, array<string, mixed>> $lines
     * @param string[] $warnings
     * @return array<int, array<string, mixed>>
     * @throws \RuntimeException when the difference is too large to call rounding
     */
    private function reconcileAgainstOrder(Order $order, array $lines, array &$warnings): array
    {
        $orderTotal = Amounts::round((float)$order->getTotalPrice());
        $gross = Amounts::round(array_sum(array_map(
            static fn(array $line) => $line['net'] + $line['tax'],
            $lines,
        )));

        $drift = Amounts::round($orderTotal - $gross);

        if (Amounts::equal($drift, 0.0)) {
            return $lines;
        }

        if (abs($drift) > self::ROUNDING_TOLERANCE) {
            $unknown = $this->unknownAdjustmentTypes($order);

            throw new \RuntimeException($unknown
                ? Craft::t('twinsies', 'The lines come to {lines} but the order totals {total}. Twinsies does not know how to express these order adjustments: {types}. Nothing was posted, because a difference this size is not rounding.', [
                    'lines' => Amounts::money($gross),
                    'total' => Amounts::money($orderTotal),
                    'types' => implode(', ', $unknown),
                ])
                : Craft::t('twinsies', 'The lines come to {lines} but the order totals {total}. Nothing was posted, because a difference this size is not rounding.', [
                    'lines' => Amounts::money($gross),
                    'total' => Amounts::money($orderTotal),
                ]));
        }

        $warnings[] = Craft::t('twinsies', 'A {amount} rounding difference was booked to the default revenue account.', [
            'amount' => Amounts::money($drift),
        ]);

        $mapping = Plugin::getInstance()->getMapping();

        $lines[] = [
            'article' => Plugin::getInstance()->getSettings()->defaultArticle ?: null,
            'subarticle' => null,
            'revenueGl' => Plugin::getInstance()->getSettings()->defaultRevenueGl ?: null,
            'vatCode' => $mapping->vatCodeFor($drift, 0.0),
            'description' => Craft::t('twinsies', 'Rounding'),
            'quantity' => 1,
            'net' => $drift,
            'tax' => 0.0,
            'freetext1' => null,
        ];

        return $lines;
    }

    /**
     * Order-level adjustment types Twinsies has no line for. These are the usual reason a
     * reconciliation fails, and naming them turns an arithmetic complaint into an instruction.
     *
     * @return string[]
     */
    private function unknownAdjustmentTypes(Order $order): array
    {
        $types = [];

        foreach ($order->getAdjustments() as $adjustment) {
            if ($adjustment->lineItemId !== null || $adjustment->included) {
                continue;
            }

            if (!in_array($adjustment->type, self::KNOWN_ADJUSTMENTS, true)) {
                $types[$adjustment->type] = $adjustment->type;
            }
        }

        return array_values($types);
    }

    private function lineAdjustmentTotal(LineItem $lineItem, string $type): float
    {
        $total = 0.0;

        foreach ($lineItem->getAdjustments() as $adjustment) {
            if ($adjustment->type === $type && !$adjustment->included) {
                $total += (float)$adjustment->amount;
            }
        }

        return Amounts::round($total);
    }

    private function lineDescription(LineItem $lineItem): string
    {
        $template = Plugin::getInstance()->getSettings()->lineDescriptionTemplate;

        if (trim($template) === '') {
            return (string)$lineItem->getDescription();
        }

        try {
            $rendered = trim(Craft::$app->getView()->renderObjectTemplate($template, $lineItem));
        } catch (\Throwable $e) {
            Craft::warning('Twinsies could not render a line description: ' . $e->getMessage(), __METHOD__);

            return (string)$lineItem->getDescription();
        }

        return $rendered !== '' ? $rendered : (string)$lineItem->getDescription();
    }

    private function renderTemplate(string $template, Order $order): ?string
    {
        if (trim($template) === '') {
            return null;
        }

        try {
            $rendered = trim(Craft::$app->getView()->renderObjectTemplate($template, $order));
        } catch (\Throwable $e) {
            Craft::warning('Twinsies could not render an invoice text: ' . $e->getMessage(), __METHOD__);

            return null;
        }

        return $rendered !== '' ? $rendered : null;
    }

    private function currency(Order $order): string
    {
        return strtoupper((string)($order->currency ?: $order->paymentCurrency ?: 'EUR'));
    }

    private function truncate(string $value, int $length = self::MAX_DESCRIPTION): string
    {
        return mb_substr(trim($value), 0, $length);
    }
}
