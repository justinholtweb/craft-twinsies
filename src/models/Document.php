<?php

namespace justinholtweb\twinsies\models;

use Craft;
use craft\base\Model;
use craft\commerce\elements\Order;
use craft\helpers\DateTimeHelper;
use DateTime;

/**
 * One thing Twinsies has posted, or is going to post, into Twinfield.
 *
 * An order has at most one `invoice` document and one `creditnote` per refund. The
 * `(orderId, sourceKey)` unique index in the database is what makes that a guarantee rather than
 * an intention.
 */
class Document extends Model
{
    public const KIND_INVOICE = 'invoice';
    public const KIND_CREDIT_NOTE = 'creditnote';

    /** Recorded but not yet sent. */
    public const STATUS_PENDING = 'pending';
    /** Handed to the queue. */
    public const STATUS_QUEUED = 'queued';
    /** Twinfield accepted it. */
    public const STATUS_SENT = 'sent';
    /** Twinfield rejected it, or it could not be built. */
    public const STATUS_FAILED = 'failed';
    /** Deliberately not posted — a zero-value order, or a rule excluded it. */
    public const STATUS_SKIPPED = 'skipped';

    public ?int $id = null;
    public ?int $orderId = null;
    public ?int $storeId = null;
    public string $kind = self::KIND_INVOICE;
    public string $sourceKey = self::KIND_INVOICE;
    public string $mode = '';
    public string $status = self::STATUS_PENDING;
    public ?string $office = null;
    public ?string $bookCode = null;
    public ?string $invoiceNumber = null;
    public ?string $transactionCode = null;
    public ?string $transactionNumber = null;
    public ?string $customerCode = null;
    public ?string $currency = null;
    public ?float $valueTotal = null;
    public ?float $openValue = null;
    public ?string $matchStatus = null;
    public ?string $payloadHash = null;
    public ?DateTime $datePosted = null;
    public ?DateTime $datePaid = null;
    public ?DateTime $dateReconciled = null;
    public int $attempts = 0;
    public ?string $lastError = null;
    public ?int $lastLogId = null;
    public ?DateTime $dateCreated = null;
    public ?DateTime $dateUpdated = null;
    public ?string $uid = null;

    private ?Order $_order = null;

    public function __construct($config = [])
    {
        foreach (['datePosted', 'datePaid', 'dateReconciled', 'dateCreated', 'dateUpdated'] as $key) {
            if (!empty($config[$key])) {
                $config[$key] = DateTimeHelper::toDateTime($config[$key]) ?: null;
            } elseif (array_key_exists($key, $config)) {
                $config[$key] = null;
            }
        }

        foreach (['valueTotal', 'openValue'] as $key) {
            if (isset($config[$key]) && $config[$key] !== '') {
                $config[$key] = (float)$config[$key];
            } elseif (array_key_exists($key, $config)) {
                $config[$key] = null;
            }
        }

        parent::__construct($config);
    }

    public function getOrder(): ?Order
    {
        if ($this->_order !== null) {
            return $this->_order;
        }

        if (!$this->orderId) {
            return null;
        }

        return $this->_order = Order::find()->id($this->orderId)->status(null)->one();
    }

    public function setOrder(?Order $order): void
    {
        $this->_order = $order;
    }

    public function isCreditNote(): bool
    {
        return $this->kind === self::KIND_CREDIT_NOTE;
    }

    public function isSent(): bool
    {
        return $this->status === self::STATUS_SENT;
    }

    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    /**
     * Whether Twinfield has booked this into a financial transaction we can reconcile against.
     */
    public function hasFinancials(): bool
    {
        return $this->transactionCode !== null && $this->transactionNumber !== null;
    }

    public function isPaid(): bool
    {
        return $this->datePaid !== null;
    }

    /**
     * How Twinfield refers to this document, for display: `FACTUUR 2026001` or `VRK 202600042`.
     */
    public function getReference(): string
    {
        if ($this->hasFinancials()) {
            return trim("{$this->transactionCode} {$this->transactionNumber}");
        }

        if ($this->invoiceNumber) {
            return trim("{$this->bookCode} {$this->invoiceNumber}");
        }

        return (string)$this->bookCode;
    }

    /**
     * The CP status dot colour.
     */
    public function getStatusColour(): string
    {
        return match ($this->status) {
            self::STATUS_SENT => $this->isPaid() ? 'green' : 'blue',
            self::STATUS_FAILED => 'red',
            self::STATUS_QUEUED => 'yellow',
            self::STATUS_SKIPPED => 'grey',
            default => 'orange',
        };
    }

    public function getStatusLabel(): string
    {
        if ($this->status === self::STATUS_SENT && $this->isPaid()) {
            return Craft::t('twinsies', 'Paid');
        }

        return match ($this->status) {
            self::STATUS_SENT => Craft::t('twinsies', 'Posted'),
            self::STATUS_FAILED => Craft::t('twinsies', 'Failed'),
            self::STATUS_QUEUED => Craft::t('twinsies', 'Queued'),
            self::STATUS_SKIPPED => Craft::t('twinsies', 'Skipped'),
            default => Craft::t('twinsies', 'Pending'),
        };
    }

    /**
     * The columns this model owns in `{{%twinsies_documents}}`.
     *
     * @return array<string, mixed>
     */
    public function toRow(): array
    {
        return [
            'orderId' => $this->orderId,
            'storeId' => $this->storeId,
            'kind' => $this->kind,
            'sourceKey' => $this->sourceKey,
            'mode' => $this->mode,
            'status' => $this->status,
            'office' => $this->office,
            'bookCode' => $this->bookCode,
            'invoiceNumber' => $this->invoiceNumber,
            'transactionCode' => $this->transactionCode,
            'transactionNumber' => $this->transactionNumber,
            'customerCode' => $this->customerCode,
            'currency' => $this->currency,
            'valueTotal' => $this->valueTotal,
            'openValue' => $this->openValue,
            'matchStatus' => $this->matchStatus,
            'payloadHash' => $this->payloadHash,
            'attempts' => $this->attempts,
            'lastError' => $this->lastError,
            'lastLogId' => $this->lastLogId,
        ];
    }
}
