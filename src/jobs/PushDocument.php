<?php

namespace justinholtweb\twinsies\jobs;

use Craft;
use craft\queue\BaseJob;
use justinholtweb\twinsies\models\Document;
use justinholtweb\twinsies\Plugin;

/**
 * Post one document to Twinfield, off the request.
 *
 * Order completion happens inside the customer's payment request. Pushing there would put an
 * accounting system's latency — and its outages — between a customer and their receipt.
 */
class PushDocument extends BaseJob
{
    public ?int $documentId = null;

    /**
     * Post again even though Twinfield already accepted this document. Only ever set by a person
     * clicking through a confirmation, never by the trigger.
     */
    public bool $force = false;

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
            // Surfacing this as a failed job is the point: a silently parked document is exactly
            // the failure mode an accounting integration must not have.
            throw new \RuntimeException($fresh->lastError ?: 'Twinfield rejected the document.');
        }
    }

    /**
     * @inheritdoc
     */
    protected function defaultDescription(): ?string
    {
        return Craft::t('twinsies', 'Posting a document to Twinfield');
    }
}
