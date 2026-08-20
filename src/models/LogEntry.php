<?php

namespace justinholtweb\twinsies\models;

use craft\base\Model;
use craft\helpers\DateTimeHelper;
use DateTime;

/**
 * One round trip to Twinfield.
 */
class LogEntry extends Model
{
    public const LEVEL_INFO = 'info';
    public const LEVEL_WARNING = 'warning';
    public const LEVEL_ERROR = 'error';

    public ?int $id = null;
    public string $action = '';
    public string $level = self::LEVEL_INFO;
    public ?int $statusCode = null;
    public ?int $durationMs = null;
    public ?int $documentId = null;
    public ?int $orderId = null;
    public ?string $summary = null;
    public ?string $message = null;
    public ?string $request = null;
    public ?string $response = null;
    public ?DateTime $dateCreated = null;
    public ?string $uid = null;

    public function __construct($config = [])
    {
        if (!empty($config['dateCreated'])) {
            $config['dateCreated'] = DateTimeHelper::toDateTime($config['dateCreated']) ?: null;
        }

        unset($config['dateUpdated']);

        parent::__construct($config);
    }

    public function isError(): bool
    {
        return $this->level === self::LEVEL_ERROR;
    }

    /**
     * The Craft CP status dot colour for this level.
     */
    public function getStatusColour(): string
    {
        return match ($this->level) {
            self::LEVEL_ERROR => 'red',
            self::LEVEL_WARNING => 'orange',
            default => 'green',
        };
    }

    public function getDurationLabel(): string
    {
        if ($this->durationMs === null) {
            return '—';
        }

        return $this->durationMs >= 1000
            ? number_format($this->durationMs / 1000, 2) . ' s'
            : $this->durationMs . ' ms';
    }
}
