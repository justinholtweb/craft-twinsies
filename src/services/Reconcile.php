<?php

namespace justinholtweb\twinsies\services;

use Craft;
use craft\base\Component;
use craft\commerce\elements\Order;
use craft\commerce\Plugin as Commerce;
use craft\commerce\records\Transaction as TransactionRecord;
use craft\db\Query;
use craft\helpers\Db;
use DateTime;
use justinholtweb\twinsies\db\Table;
use justinholtweb\twinsies\helpers\Amounts;
use justinholtweb\twinsies\helpers\Xml;
use justinholtweb\twinsies\models\Document;
use justinholtweb\twinsies\models\LogEntry;
use justinholtweb\twinsies\Plugin;

/**
 * Reading payment back out of Twinfield.
 *
 * Twinfield is where a bank statement gets matched against an invoice, so for anything not paid by
 * card at checkout — on-account B2B, bank transfer, iDEAL reconciled after the fact — Twinfield
 * knows the order is paid before Craft does. This walks documents that are still open and asks.
 *
 * Two things worth knowing about the shape of that question:
 *
 * - **There is nothing to ask about until the document is booked.** A concept invoice or a
 *   provisional transaction is not a financial transaction, so it has no open value and no match
 *   status. {@see Settings::canReconcile()} is what stops this running pointlessly.
 * - **The open value is on the *total* line, and the element is misnamed.** Twinfield's own
 *   documentation calls it `<valueopen>`; live responses send `<openvalue>`. Both are read.
 */
class Reconcile extends Component
{
    public const MATCH_AVAILABLE = 'available';
    public const MATCH_MATCHED = 'matched';
    public const MATCH_NOT_MATCHABLE = 'notmatchable';

    /**
     * Check the next batch of open documents.
     *
     * @return array{checked: int, paid: int, errors: int}
     */
    public function run(?int $limit = null): array
    {
        $settings = Plugin::getInstance()->getSettings();

        if (!$settings->canReconcile()) {
            return ['checked' => 0, 'paid' => 0, 'errors' => 0];
        }

        $checked = 0;
        $paid = 0;
        $errors = 0;

        foreach ($this->findOpen($limit ?? $settings->reconcileBatchSize) as $document) {
            $checked++;

            try {
                if ($this->check($document)) {
                    $paid++;
                }
            } catch (\Throwable $e) {
                $errors++;

                Plugin::getInstance()->getLog()->write('reconcile.error', [
                    'level' => LogEntry::LEVEL_ERROR,
                    'summary' => $e->getMessage(),
                    'orderId' => $document->orderId,
                    'documentId' => $document->id,
                ]);
            }
        }

        return compact('checked', 'paid', 'errors');
    }

    /**
     * Read one document's financial transaction and record what Twinfield says about it.
     *
     * @return bool whether this call is what discovered the document had been paid
     */
    public function check(Document $document): bool
    {
        if (!$document->hasFinancials()) {
            return false;
        }

        $api = Plugin::getInstance()->getApi();
        $sync = Plugin::getInstance()->getSync();

        $response = $api->read([
            'type' => 'transaction',
            'office' => $document->office,
            'code' => $document->transactionCode,
            'number' => $document->transactionNumber,
        ], $document->office, 'reconcile.read');

        if (!Xml::succeeded($response)) {
            throw new \RuntimeException(Craft::t('twinsies', 'Twinfield would not return the transaction: {reason}', ['reason' => Xml::summariseErrors($response)]));
        }

        $state = $this->readTotalLine($response);

        if ($state === null) {
            throw new \RuntimeException(Craft::t('twinsies', 'The transaction Twinfield returned has no total line with an open value to read.'));
        }

        $wasPaid = $document->isPaid();
        $isPaid = Amounts::equal($state['openValue'], 0.0);

        $sync->update($document, [
            'openValue' => $state['openValue'],
            'matchStatus' => $state['matchStatus'],
            'dateReconciled' => Db::prepareDateForDb(new DateTime()),
            'datePaid' => $isPaid
                ? Db::prepareDateForDb($document->datePaid ?? new DateTime())
                : null,
        ]);

        if (!$isPaid || $wasPaid) {
            return false;
        }

        $this->applyPayment($document);

        return true;
    }

    /**
     * Documents that are booked, not yet paid, and recent enough to still be worth asking about.
     *
     * @return Document[]
     */
    public function findOpen(int $limit = 50): array
    {
        $settings = Plugin::getInstance()->getSettings();
        $cutoff = (new DateTime())->modify('-' . max(1, $settings->reconcileLookbackDays) . ' days');

        return array_map(
            static fn(array $row) => new Document($row),
            (new Query())
                ->from([Table::DOCUMENTS])
                ->where([
                    'status' => Document::STATUS_SENT,
                    'kind' => Document::KIND_INVOICE,
                    'datePaid' => null,
                ])
                ->andWhere(['not', ['transactionNumber' => null]])
                ->andWhere(['>=', 'datePosted', Db::prepareDateForDb($cutoff)])
                // Longest since last checked first, so a large backlog is worked evenly rather
                // than the same fifty documents being re-read every run.
                ->orderBy(['dateReconciled' => SORT_ASC, 'datePosted' => SORT_ASC])
                ->limit($limit)
                ->all()
        );
    }

    /**
     * Reflect a Twinfield payment on the Commerce order.
     */
    public function applyPayment(Document $document): void
    {
        $settings = Plugin::getInstance()->getSettings();
        $order = $document->getOrder();

        if ($order === null) {
            return;
        }

        if ($settings->markOrderPaid) {
            $this->recordCommercePayment($order, $document);
        }

        $this->moveToPaidStatus($order, $settings->paidOrderStatusHandle, $document);

        Plugin::getInstance()->getLog()->write('reconcile.paid', [
            'summary' => Craft::t('twinsies', 'Twinfield reports {reference} as paid.', ['reference' => $document->getReference()]),
            'orderId' => $order->id,
            'documentId' => $document->id,
        ]);
    }

    /**
     * The open value and match status from the transaction's total line.
     *
     * @return array{openValue: float, matchStatus: string|null}|null
     */
    private function readTotalLine(\DOMDocument $response): ?array
    {
        $transaction = Xml::first($response, 'transaction');

        if ($transaction === null) {
            return null;
        }

        $linesEl = Xml::first($transaction, 'lines');

        if ($linesEl === null) {
            return null;
        }

        foreach (Xml::children($linesEl, 'line') as $line) {
            if ($line->getAttribute('type') !== 'total') {
                continue;
            }

            // The docs say `<valueopen>`; the service sends `<openvalue>`. Reading only the
            // documented one means every invoice looks permanently unpaid.
            $open = Xml::childText($line, 'openvalue') ?? Xml::childText($line, 'valueopen');
            $matchStatus = Xml::childText($line, 'matchstatus');

            if ($open === null || !is_numeric($open)) {
                // No open value at all is not "nothing open" — reading it that way records a
                // Commerce payment nobody made. Only a line Twinfield itself calls matched is
                // settled without one.
                if ($matchStatus !== 'matched') {
                    return null;
                }

                $open = '0';
            }

            return [
                'openValue' => (float)$open,
                'matchStatus' => $matchStatus,
            ];
        }

        return null;
    }

    /**
     * Record a Commerce payment transaction so the order counts as paid.
     *
     * Only ever called when the merchant has explicitly asked for it. Commerce's paid state is
     * normally owned by a payment gateway, and writing a payment that no gateway took is a claim
     * about money — one worth making deliberately rather than as a side effect of a status sync.
     */
    private function recordCommercePayment(Order $order, Document $document): void
    {
        $outstanding = (float)$order->getOutstandingBalance();

        if (Amounts::equal($outstanding, 0.0) || $outstanding < 0) {
            return;
        }

        try {
            $commerce = Commerce::getInstance();
            $transaction = $commerce->getTransactions()->createTransaction($order, null, TransactionRecord::TYPE_PURCHASE);
            $transaction->status = TransactionRecord::STATUS_SUCCESS;
            // `amount` and `paymentAmount` are left as createTransaction() set them: the
            // outstanding balance in the store currency and in the order's payment currency, with
            // the rate between them. Setting both to the store-currency balance recorded the wrong
            // payment for any order paid in another currency.
            $transaction->reference = mb_substr($document->getReference(), 0, 255);
            $transaction->note = Craft::t('twinsies', 'Matched in Twinfield');

            $commerce->getTransactions()->saveTransaction($transaction);

            // Commerce recalculates `isPaid`/`datePaid` from its transactions rather than storing
            // them, so the order has to be told to look again.
            if (method_exists($order, 'updateOrderPaidInformation')) {
                $order->updateOrderPaidInformation();
            }
        } catch (\Throwable $e) {
            // An order taken on account may have no gateway at all, in which case Commerce
            // refuses the transaction. That is not a reason to skip the status change below.
            Craft::warning('Twinsies could not record a Commerce payment for order ' . $order->id . ': ' . $e->getMessage(), __METHOD__);

            Plugin::getInstance()->getLog()->write('reconcile.warning', [
                'level' => LogEntry::LEVEL_WARNING,
                'summary' => Craft::t('twinsies', 'Twinfield reports this order paid, but Commerce would not accept a payment transaction: {error}', [
                    'error' => $e->getMessage(),
                ]),
                'orderId' => $order->id,
                'documentId' => $document->id,
            ]);
        }
    }

    private function moveToPaidStatus(Order $order, string $statusHandle, Document $document): void
    {
        $statusHandle = trim($statusHandle);

        if ($statusHandle === '') {
            return;
        }

        try {
            $status = Commerce::getInstance()->getOrderStatuses()->getOrderStatusByHandle($statusHandle, $order->storeId);
        } catch (\Throwable) {
            $status = null;
        }

        if ($status === null || $order->orderStatusId === $status->id) {
            return;
        }

        $order->orderStatusId = $status->id;
        // Commerce writes an order history — and sends the status email — only when the status
        // actually changes, and it takes the note from `message`.
        $order->message = Craft::t('twinsies', 'Paid according to Twinfield ({reference}).', [
            'reference' => $document->getReference(),
        ]);

        if (!Craft::$app->getElements()->saveElement($order, false)) {
            Craft::warning('Twinsies could not move order ' . $order->id . ' to the paid status.', __METHOD__);
        }
    }
}
