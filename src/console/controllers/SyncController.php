<?php

namespace justinholtweb\twinsies\console\controllers;

use craft\commerce\elements\Order;
use craft\console\Controller;
use craft\helpers\Console;
use justinholtweb\twinsies\helpers\Xml;
use justinholtweb\twinsies\models\Document;
use justinholtweb\twinsies\Plugin;
use yii\console\ExitCode;

/**
 * Posting orders to Twinfield from the command line.
 *
 * Run as `craft twinsies/sync/<action>`.
 */
class SyncController extends Controller
{
    /**
     * How many orders or documents to work through.
     */
    public int $limit = 50;

    /**
     * Post again even if Twinfield already accepted the document. Unless the site sends its own
     * invoice numbers this creates a second document rather than replacing the first.
     */
    public bool $force = false;

    /**
     * Push through the queue instead of inline.
     */
    public bool $queue = false;

    /**
     * @inheritdoc
     */
    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), match ($actionID) {
            'order', 'pending', 'retry' => ['limit', 'force', 'queue'],
            default => [],
        });
    }

    /**
     * Post a single order, by order reference, order number or element ID.
     */
    public function actionOrder(string $reference): int
    {
        $order = $this->findOrder($reference);

        if ($order === null) {
            $this->stderr("No completed order matches “{$reference}”.\n", Console::FG_RED);

            return ExitCode::DATAERR;
        }

        $document = Plugin::getInstance()->getSync()->pushOrder($order, $this->force, $this->queue);

        if ($this->queue) {
            $this->stdout("Queued order {$order->reference}.\n", Console::FG_GREEN);

            return ExitCode::OK;
        }

        if ($document->isSent()) {
            $this->stdout("Posted {$order->reference} as {$document->getReference()}.\n", Console::FG_GREEN);

            return ExitCode::OK;
        }

        $this->stderr("Failed: {$document->lastError}\n", Console::FG_RED);

        return ExitCode::UNSPECIFIED_ERROR;
    }

    /**
     * Post completed orders that have no document yet.
     */
    public function actionPending(): int
    {
        $sync = Plugin::getInstance()->getSync();
        $orders = $sync->findUnposted($this->limit);

        if (!$orders) {
            $this->stdout("Nothing to post.\n");

            return ExitCode::OK;
        }

        $posted = 0;
        $failed = 0;

        foreach ($orders as $order) {
            $document = $sync->pushOrder($order, false, $this->queue);

            if ($this->queue || $document->isSent()) {
                $posted++;
                $this->stdout("  ✓ {$order->reference}\n", Console::FG_GREEN);
                continue;
            }

            $failed++;
            $this->stdout("  ✗ {$order->reference}: {$document->lastError}\n", Console::FG_RED);
        }

        $this->stdout("\n{$posted} posted, {$failed} failed.\n");

        return $failed > 0 ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }

    /**
     * Retry documents that failed and have attempts left.
     */
    public function actionRetry(): int
    {
        $sync = Plugin::getInstance()->getSync();
        $documents = $sync->findRetryable($this->limit);

        if (!$documents) {
            $this->stdout("Nothing to retry.\n");

            return ExitCode::OK;
        }

        $recovered = 0;

        foreach ($documents as $document) {
            $creditAmount = $document->kind === Document::KIND_CREDIT_NOTE ? $document->valueTotal : null;

            if ($this->queue) {
                $sync->enqueue($document);
                $recovered++;
                continue;
            }

            if ($sync->push($document, false, $creditAmount)) {
                $recovered++;
                $this->stdout("  ✓ #{$document->id}\n", Console::FG_GREEN);
            } else {
                $fresh = $sync->getDocumentById($document->id);
                $this->stdout("  ✗ #{$document->id}: {$fresh?->lastError}\n", Console::FG_RED);
            }
        }

        $this->stdout("\n{$recovered} of " . count($documents) . " recovered.\n");

        return ExitCode::OK;
    }

    /**
     * Print the XML an order would post, without sending it.
     */
    public function actionPreview(string $reference, string $kind = Document::KIND_INVOICE): int
    {
        $order = $this->findOrder($reference);

        if ($order === null) {
            $this->stderr("No completed order matches “{$reference}”.\n", Console::FG_RED);

            return ExitCode::DATAERR;
        }

        try {
            $built = Plugin::getInstance()->getDocuments()->build($order, $kind);
        } catch (\Throwable $e) {
            $this->stderr("Could not build the document: {$e->getMessage()}\n", Console::FG_RED);

            return ExitCode::UNSPECIFIED_ERROR;
        }

        foreach ($built->warnings as $warning) {
            $this->stdout("  ! {$warning}\n", Console::FG_YELLOW);
        }

        $this->stdout("\n" . Xml::pretty($built->xml) . "\n");

        return ExitCode::OK;
    }

    /**
     * A summary of what Twinsies has posted.
     */
    public function actionStatus(): int
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();
        $counts = $plugin->getSync()->getStatusCounts();

        $this->stdout("Twinsies\n", Console::FG_CYAN, Console::BOLD);
        $this->stdout('  Mode:      ' . ($settings->isTransactionMode() ? 'journal transactions' : 'sales invoices') . "\n");
        $this->stdout('  Office:    ' . ($settings->getOffice() ?: '(not set)') . "\n");
        $this->stdout('  Connected: ' . ($plugin->getAuth()->isConnected() ? 'yes' : 'no') . "\n\n");

        foreach ($counts as $status => $count) {
            $this->stdout(sprintf("  %-10s %d\n", $status, $count));
        }

        return ExitCode::OK;
    }

    /**
     * Orders are looked up three ways because all three are what a person has to hand: the
     * reference on the invoice, the long order number in a URL, and the element ID.
     */
    private function findOrder(string $reference): ?Order
    {
        $reference = trim($reference);

        $order = Order::find()->reference($reference)->status(null)->one()
            ?? Order::find()->number($reference)->status(null)->one();

        if ($order === null && ctype_digit($reference)) {
            $order = Order::find()->id((int)$reference)->status(null)->one();
        }

        return $order;
    }
}
