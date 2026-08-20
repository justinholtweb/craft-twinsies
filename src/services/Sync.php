<?php

namespace justinholtweb\twinsies\services;

use Craft;
use craft\base\Component;
use craft\commerce\elements\Order;
use craft\commerce\models\Transaction;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use DateTime;
use justinholtweb\twinsies\db\Table;
use justinholtweb\twinsies\helpers\Amounts;
use justinholtweb\twinsies\helpers\Xml;
use justinholtweb\twinsies\models\BuiltDocument;
use justinholtweb\twinsies\models\Document;
use justinholtweb\twinsies\models\LogEntry;
use justinholtweb\twinsies\models\Settings;
use justinholtweb\twinsies\Plugin;
use justinholtweb\twinsies\jobs\PushDocument;
use yii\db\IntegrityException;

/**
 * Getting documents into Twinfield, and knowing which ones already are.
 *
 * **`record()` is the only place a document row is created, and `push()` the only place one is
 * sent.** Order completion, the control panel button, the console command and the queue job all
 * come through them, so the answer to "has this order been posted?" is decided once.
 *
 * The `(orderId, sourceKey)` unique index does the actual work. Order completion can fire more
 * than once — a retried webhook, a merchant re-completing an order by hand, two queue workers —
 * and a duplicate insert has to lose in the database rather than in a race between two `if`s.
 *
 * Nothing here is allowed to throw into checkout. Twinfield being down must never stop a customer
 * paying, so the trigger path catches everything and leaves a failed row to retry.
 */
class Sync extends Component
{
    /**
     * @throws \Throwable
     */
    public function getDocument(int $orderId, string $sourceKey = Document::KIND_INVOICE): ?Document
    {
        $row = (new Query())
            ->from([Table::DOCUMENTS])
            ->where(['orderId' => $orderId, 'sourceKey' => $sourceKey])
            ->one();

        return $row ? new Document($row) : null;
    }

    public function getDocumentById(int $id): ?Document
    {
        $row = (new Query())->from([Table::DOCUMENTS])->where(['id' => $id])->one();

        return $row ? new Document($row) : null;
    }

    /**
     * Every document for an order — the invoice and any credit notes.
     *
     * @return Document[]
     */
    public function getDocumentsForOrder(int $orderId): array
    {
        return array_map(
            static fn(array $row) => new Document($row),
            (new Query())
                ->from([Table::DOCUMENTS])
                ->where(['orderId' => $orderId])
                ->orderBy(['id' => SORT_ASC])
                ->all()
        );
    }

    /**
     * @return Document[]
     */
    public function getDocuments(array $criteria = [], int $limit = 100, int $offset = 0): array
    {
        return array_map(
            static fn(array $row) => new Document($row),
            $this->buildQuery($criteria)->limit($limit)->offset($offset)->all()
        );
    }

    public function countDocuments(array $criteria = []): int
    {
        return (int)$this->buildQuery($criteria)->count();
    }

    /**
     * @return array<string, int> status => count
     */
    public function getStatusCounts(): array
    {
        $counts = array_fill_keys([
            Document::STATUS_PENDING,
            Document::STATUS_QUEUED,
            Document::STATUS_SENT,
            Document::STATUS_FAILED,
            Document::STATUS_SKIPPED,
        ], 0);

        foreach ((new Query())
            ->select(['status', 'total' => 'COUNT(*)'])
            ->from([Table::DOCUMENTS])
            ->groupBy(['status'])
            ->all() as $row) {
            $counts[$row['status']] = (int)$row['total'];
        }

        return $counts;
    }

    // Recording
    // -------------------------------------------------------------------------

    /**
     * Find or create the row for a document, without sending anything.
     *
     * The insert races deliberately: two workers both find nothing, both insert, and the unique
     * index picks a winner. The loser reads the winner's row rather than posting a second invoice.
     */
    public function record(Order $order, string $kind = Document::KIND_INVOICE, ?string $sourceKey = null): Document
    {
        $sourceKey ??= $kind;
        $existing = $this->getDocument($order->id, $sourceKey);

        if ($existing !== null) {
            $existing->setOrder($order);

            return $existing;
        }

        $settings = Plugin::getInstance()->getSettings();
        $now = Db::prepareDateForDb(new DateTime());

        try {
            Craft::$app->getDb()->createCommand()->insert(Table::DOCUMENTS, [
                'orderId' => $order->id,
                'storeId' => $order->storeId ?? null,
                'kind' => $kind,
                'sourceKey' => $sourceKey,
                'mode' => $settings->mode,
                'status' => Document::STATUS_PENDING,
                'office' => $settings->getOffice(),
                'currency' => strtoupper((string)$order->currency),
                'valueTotal' => (float)$order->getTotalPrice(),
                'dateCreated' => $now,
                'dateUpdated' => $now,
                'uid' => StringHelper::UUID(),
            ])->execute();
        } catch (IntegrityException) {
            // Someone else got there first. Their row is the one that counts.
        }

        $document = $this->getDocument($order->id, $sourceKey);

        if ($document === null) {
            throw new \RuntimeException('Twinsies could not record a document row for this order.');
        }

        $document->setOrder($order);

        return $document;
    }

    /**
     * Hand a document to the queue.
     */
    public function enqueue(Document $document): void
    {
        $this->update($document, ['status' => Document::STATUS_QUEUED, 'lastError' => null]);

        Craft::$app->getQueue()->push(new PushDocument([
            'documentId' => $document->id,
        ]));
    }

    // Pushing
    // -------------------------------------------------------------------------

    /**
     * Build and send a document.
     *
     * @param bool $force post again even though this document has already been accepted. Twinfield
     *                    has no idea it is a repost, so unless the site sends its own invoice
     *                    numbers this creates a *second* document rather than replacing the first.
     * @return bool whether Twinfield accepted it
     */
    public function push(Document $document, bool $force = false, ?float $creditAmount = null): bool
    {
        $order = $document->getOrder();

        if ($order === null) {
            $this->fail($document, 'The order this document belongs to no longer exists.');

            return false;
        }

        $mutex = Craft::$app->getMutex();
        $lock = 'twinsies:order:' . $order->id;

        // The unique index stops a second *row*. It cannot undo a second *invoice* that Twinfield
        // has already created, which is what two workers both passing the "already sent?" check
        // would produce. 60s is long enough for a slow Twinfield and short enough that a wedged
        // worker does not park an order forever.
        if (!$mutex->acquire($lock, 60)) {
            $this->fail($document, 'Another process is already posting this order.');

            return false;
        }

        try {
            return $this->doPush($document, $order, $force, $creditAmount);
        } finally {
            $mutex->release($lock);
        }
    }

    private function doPush(Document $document, Order $order, bool $force, ?float $creditAmount): bool
    {
        $plugin = Plugin::getInstance();

        // Re-read inside the lock: the worker that held it may have posted this very document
        // while this one was waiting.
        $fresh = $this->getDocumentById($document->id);

        if ($fresh !== null && $fresh->isSent() && !$force) {
            return true;
        }

        if ($document->isSent() && !$force) {
            return true;
        }

        try {
            $built = $plugin->getDocuments()->build($order, $document->kind, $creditAmount);
        } catch (\Throwable $e) {
            $this->fail($document, $e->getMessage());

            return false;
        }

        foreach ($built->warnings as $warning) {
            $plugin->getLog()->write('build.warning', [
                'level' => LogEntry::LEVEL_WARNING,
                'summary' => $warning,
                'orderId' => $order->id,
                'documentId' => $document->id,
            ]);
        }

        try {
            $response = $plugin->getApi()->process(
                $built->xml,
                'push.' . $document->kind,
                $built->office,
                $order->id,
                $document->id,
            );
        } catch (\Throwable $e) {
            $this->fail($document, $e->getMessage(), $built);

            return false;
        }

        if (!Xml::succeeded($response)) {
            $this->fail($document, Xml::summariseErrors($response), $built);

            return false;
        }

        $this->succeed($document, $built, $response);

        return true;
    }

    /**
     * Record and push an order's invoice in one step.
     */
    public function pushOrder(Order $order, bool $force = false, bool $queue = false): Document
    {
        $document = $this->record($order);

        if ($queue) {
            $this->enqueue($document);

            return $document;
        }

        $this->push($document, $force);

        return $this->getDocumentById($document->id) ?? $document;
    }

    /**
     * Record and push a credit note for a Commerce refund.
     *
     * The source key is the refund transaction's own hash, so two partial refunds produce two
     * credit notes and a retried refund webhook produces one.
     */
    public function pushRefund(Order $order, Transaction $refund, bool $queue = false): ?Document
    {
        $settings = Plugin::getInstance()->getSettings();

        if (!$settings->creditNotesEnabled) {
            return null;
        }

        $amount = abs((float)$refund->amount);

        if (Amounts::equal($amount, 0.0)) {
            return null;
        }

        $sourceKey = 'refund:' . ($refund->hash ?: (string)$refund->id);
        $document = $this->record($order, Document::KIND_CREDIT_NOTE, $sourceKey);

        // The credited amount is not derivable from the order later — a second refund changes what
        // "the refunded amount" means — so it is stamped on the row when the refund happens.
        $this->update($document, ['valueTotal' => $amount]);
        $document->valueTotal = $amount;

        if ($queue) {
            $this->enqueue($document);

            return $document;
        }

        $this->push($document, false, $amount);

        return $this->getDocumentById($document->id) ?? $document;
    }

    // Triggers
    // -------------------------------------------------------------------------

    /**
     * Whether this order should be posted at all, given the trigger settings.
     */
    public function shouldSync(Order $order, bool $fromStatusChange = false): bool
    {
        $settings = Plugin::getInstance()->getSettings();

        if (!$order->id || !$order->isCompleted) {
            return false;
        }

        // A plugin that has never been configured must not start recording documents and filling
        // the queue with jobs that cannot succeed. This is deliberately "never set up" rather than
        // "cannot reach Twinfield right now": a momentary outage should still queue the order so
        // it retries, which is the whole point of having a queue.
        if (!Plugin::getInstance()->isConfigured()) {
            return false;
        }

        return match ($settings->trigger) {
            Settings::TRIGGER_COMPLETE => !$fromStatusChange,
            Settings::TRIGGER_STATUS => $fromStatusChange
                && in_array($order->getOrderStatus()?->handle, $settings->triggerStatusHandles, true),
            default => false,
        };
    }

    /**
     * The trigger path. Catches everything: this runs inside order completion, which runs inside
     * the customer's payment request.
     */
    public function handleOrder(Order $order, bool $fromStatusChange = false): void
    {
        try {
            if (!$this->shouldSync($order, $fromStatusChange)) {
                return;
            }

            $settings = Plugin::getInstance()->getSettings();
            $document = $this->record($order);

            if ($document->isSent() || $document->status === Document::STATUS_QUEUED) {
                return;
            }

            if ($settings->queuePush) {
                $this->enqueue($document);

                return;
            }

            $this->push($document);
        } catch (\Throwable $e) {
            Craft::error('Twinsies could not post order ' . $order->id . ': ' . $e->getMessage(), __METHOD__);

            Plugin::getInstance()->getLog()->write('trigger.error', [
                'level' => LogEntry::LEVEL_ERROR,
                'summary' => $e->getMessage(),
                'orderId' => $order->id,
            ]);
        }
    }

    /**
     * Orders that are completed, in scope, and have no document yet.
     *
     * @return Order[]
     */
    public function findUnposted(int $limit = 50, ?DateTime $since = null): array
    {
        $query = Order::find()
            ->isCompleted(true)
            ->status(null)
            ->orderBy(['commerce_orders.dateOrdered' => SORT_ASC])
            ->limit($limit);

        if ($since !== null) {
            $query->dateOrdered('>= ' . $since->format('Y-m-d H:i:s'));
        }

        $posted = (new Query())
            ->select(['orderId'])
            ->from([Table::DOCUMENTS])
            ->where(['kind' => Document::KIND_INVOICE])
            ->column();

        if ($posted) {
            $query->andWhere(['not', ['elements.id' => $posted]]);
        }

        return $query->all();
    }

    /**
     * Documents that failed and have retries left.
     *
     * @return Document[]
     */
    public function findRetryable(int $limit = 50): array
    {
        return array_map(
            static fn(array $row) => new Document($row),
            (new Query())
                ->from([Table::DOCUMENTS])
                ->where(['status' => [Document::STATUS_FAILED, Document::STATUS_PENDING]])
                ->andWhere(['<', 'attempts', Plugin::getInstance()->getSettings()->maxAttempts])
                ->orderBy(['dateUpdated' => SORT_ASC])
                ->limit($limit)
                ->all()
        );
    }

    // Row maintenance
    // -------------------------------------------------------------------------

    /**
     * @param array<string, mixed> $values
     */
    public function update(Document $document, array $values): void
    {
        if (!$document->id) {
            return;
        }

        $values['dateUpdated'] = Db::prepareDateForDb(new DateTime());

        Craft::$app->getDb()->createCommand()->update(Table::DOCUMENTS, $values, ['id' => $document->id])->execute();

        // Re-read rather than copying `$values` onto the model. Those values are in database
        // shape — dates are `Db::prepareDateForDb()` strings — and assigning one to a `?DateTime`
        // property is a TypeError, not a coercion. The constructor is the only thing that knows
        // how to turn a row back into a model.
        $row = (new Query())->from([Table::DOCUMENTS])->where(['id' => $document->id])->one();

        if (!$row) {
            return;
        }

        $fresh = new Document($row);

        foreach (get_object_vars($fresh) as $key => $value) {
            $document->$key = $value;
        }
    }

    public function delete(Document $document): bool
    {
        if (!$document->id) {
            return false;
        }

        return (bool)Craft::$app->getDb()->createCommand()
            ->delete(Table::DOCUMENTS, ['id' => $document->id])
            ->execute();
    }

    /**
     * Mark a document as deliberately not posted.
     */
    public function skip(Document $document, string $reason): void
    {
        $this->update($document, [
            'status' => Document::STATUS_SKIPPED,
            'lastError' => $reason,
        ]);
    }

    private function fail(Document $document, string $message, ?BuiltDocument $built = null): void
    {
        $this->update($document, [
            'status' => Document::STATUS_FAILED,
            'attempts' => $document->attempts + 1,
            'lastError' => mb_substr($message, 0, 2000),
            'payloadHash' => $built?->hash() ?? $document->payloadHash,
        ]);

        Plugin::getInstance()->getLog()->write('push.failed', [
            'level' => LogEntry::LEVEL_ERROR,
            'summary' => $message,
            'orderId' => $document->orderId,
            'documentId' => $document->id,
            'request' => $built?->xml,
        ]);
    }

    private function succeed(Document $document, BuiltDocument $built, \DOMDocument $response): void
    {
        $financials = $this->readFinancials($response, $built->mode);

        $this->update($document, [
            'status' => Document::STATUS_SENT,
            'mode' => $built->mode,
            'office' => $built->office,
            'bookCode' => $built->bookCode,
            'customerCode' => $built->customerCode,
            'currency' => $built->currency,
            'valueTotal' => $built->total,
            'invoiceNumber' => $financials['invoiceNumber'],
            'transactionCode' => $financials['transactionCode'],
            'transactionNumber' => $financials['transactionNumber'],
            'payloadHash' => $built->hash(),
            'attempts' => $document->attempts + 1,
            'lastError' => null,
            'datePosted' => Db::prepareDateForDb(new DateTime()),
        ]);
    }

    /**
     * Pull the resulting document's identity out of Twinfield's response.
     *
     * In transaction mode the header carries the daybook and number straight away. In sales
     * invoice mode the `<financials>` block only appears once the invoice is `final` — a concept
     * invoice is not a financial transaction yet and there is genuinely nothing to record.
     *
     * @return array{invoiceNumber: string|null, transactionCode: string|null, transactionNumber: string|null}
     */
    private function readFinancials(\DOMDocument $response, string $mode): array
    {
        $result = ['invoiceNumber' => null, 'transactionCode' => null, 'transactionNumber' => null];

        if ($mode === Settings::MODE_TRANSACTION) {
            $header = Xml::first($response, 'header');

            if ($header !== null) {
                $result['transactionCode'] = Xml::childText($header, 'code');
                $result['transactionNumber'] = Xml::childText($header, 'number');
                $result['invoiceNumber'] = Xml::childText($header, 'invoicenumber');
            }

            return $result;
        }

        $header = Xml::first($response, 'header');

        if ($header !== null) {
            $result['invoiceNumber'] = Xml::childText($header, 'invoicenumber');
        }

        $financials = Xml::first($response, 'financials');

        if ($financials !== null) {
            $result['transactionCode'] = Xml::childText($financials, 'code');
            $result['transactionNumber'] = Xml::childText($financials, 'number');
        }

        return $result;
    }

    private function buildQuery(array $criteria): Query
    {
        $query = (new Query())
            ->from([Table::DOCUMENTS])
            ->orderBy(['dateCreated' => SORT_DESC, 'id' => SORT_DESC]);

        if (!empty($criteria['status'])) {
            $query->andWhere(['status' => $criteria['status']]);
        }

        if (!empty($criteria['kind'])) {
            $query->andWhere(['kind' => $criteria['kind']]);
        }

        if (!empty($criteria['office'])) {
            $query->andWhere(['office' => $criteria['office']]);
        }

        if (isset($criteria['paid'])) {
            $query->andWhere($criteria['paid'] ? ['not', ['datePaid' => null]] : ['datePaid' => null]);
        }

        if (!empty($criteria['orderId'])) {
            $query->andWhere(['orderId' => $criteria['orderId']]);
        }

        return $query;
    }
}
