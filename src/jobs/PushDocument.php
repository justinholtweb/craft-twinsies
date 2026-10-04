<?php

namespace justinholtweb\twinsies\jobs;

use Craft;
use craft\queue\BaseJob;
use justinholtweb\twinsies\models\Document;
use justinholtweb\twinsies\Plugin;
use yii\queue\RetryableJobInterface;

/**
 * Post one document to Twinfield, off the request.
 *
 * Order completion happens inside the customer's payment request. Pushing there would put an
 * accounting system's latency — and its outages — between a customer and their receipt.
 */
class PushDocument extends BaseJob implements RetryableJobInterface
{
    public ?int $documentId = null;

    /**
     * Post again even though Twinfield already accepted this document. Only ever set by a person
     * clicking through a confirmation, never by the trigger.
     */
    public bool $force = false;

    /**
     * Set when this run failed because Twinfield was busy or unreachable — not serialised, only
     * read by canRetry() on the same instance that just ran.
     */
    private bool $transient = false;

    /**
     * @inheritdoc
     */
    public function execute($queue): void
    {
        $plugin = Plugin::getInstance();
        $document = $this->documentId ? $plugin->getSync()->getDocumentById($this->documentId) : null;

        if ($document === null) {
            // The order was deleted between queueing and running. Nothing to do, and nothing
            // worth failing a job over.
            return;
        }

        $this->setProgress($queue, 0.1, Craft::t('twinsies', 'Building the document'));

        // A credit note's amount was stamped on the row when the refund happened; the order alone
        // no longer says how much this particular refund was for.
        $creditAmount = $document->kind === Document::KIND_CREDIT_NOTE ? $document->valueTotal : null;

        $plugin->getSync()->push($document, $this->force, $creditAmount);

        $this->setProgress($queue, 1);

        $fresh = $plugin->getSync()->getDocumentById($this->documentId);

        if ($fresh !== null && $fresh->isFailed()) {
            $this->transient = $plugin->getSync()->lastFailureWasTransient();

            // Surfacing this as a failed job is the point: a silently parked document is exactly
            // the failure mode an accounting integration must not have.
            throw new \RuntimeException($fresh->lastError ?: Craft::t('twinsies', 'Twinfield rejected the document.'));
        }
    }

    /**
     * A push can be a debtor write, article reads and the post itself, each allowed `timeout`
     * seconds over `maxAttempts` tries. Craft's default of 300 seconds would declare a slow but
     * healthy push dead and run it a second time alongside the first.
     */
    public function getTtr(): int
    {
        $settings = Plugin::getInstance()->getSettings();

        return max(300, $settings->timeout * $settings->maxAttempts * 4 + 60);
    }

    /**
     * Only an outage is worth waiting out. A document Twinfield refused fails the same way next
     * time, and one it may already have accepted must not be sent again by a machine.
     */
    public function canRetry($attempt, $error): bool
    {
        return $this->transient && $attempt < Plugin::getInstance()->getSettings()->maxAttempts;
    }

    /**
     * @inheritdoc
     */
    protected function defaultDescription(): ?string
    {
        $document = $this->documentId ? Plugin::getInstance()->getSync()->getDocumentById($this->documentId) : null;

        return $document?->orderId
            ? Craft::t('twinsies', 'Posting order {id} to Twinfield', ['id' => $document->orderId])
            : Craft::t('twinsies', 'Posting a document to Twinfield');
    }
}
