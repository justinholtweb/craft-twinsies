<?php

namespace justinholtweb\twinsies\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use DateTime;
use justinholtweb\twinsies\db\Table;
use justinholtweb\twinsies\models\LogEntry;
use justinholtweb\twinsies\Plugin;

/**
 * The Twinfield connection log.
 *
 * An accounting integration that fails silently is worse than one that does not exist: the
 * merchant finds out at the quarter's VAT return. Twinfield reports rejections *inside* a
 * 200 OK response, as attributes on whichever tag it disliked, so the response body is the only
 * place the reason ever appears. Keeping it is the point of this table.
 */
class Log extends Component
{
    /**
     * Payloads over this are truncated. A backfill document runs to megabytes and nobody reads
     * past the first screen.
     */
    public const MAX_PAYLOAD = 262144;

    /**
     * @param array{
     *     level?: string,
     *     statusCode?: int|null,
     *     durationMs?: int|null,
     *     summary?: string|null,
     *     message?: string|null,
     *     request?: string|null,
     *     response?: string|null,
     *     orderId?: int|null,
     *     documentId?: int|null,
     * } $data
     */
    public function write(string $action, array $data = []): ?int
    {
        $settings = Plugin::getInstance()->getSettings();

        if (!$settings->loggingEnabled) {
            return null;
        }

        try {
            $db = Craft::$app->getDb();

            $db->createCommand()->insert(Table::LOG, [
                'action' => mb_substr($action, 0, 48),
                'level' => $data['level'] ?? LogEntry::LEVEL_INFO,
                'statusCode' => $data['statusCode'] ?? null,
                'durationMs' => $data['durationMs'] ?? null,
                'documentId' => $data['documentId'] ?? null,
                'orderId' => $data['orderId'] ?? null,
                'summary' => isset($data['summary']) ? mb_substr($this->redact((string)$data['summary']), 0, 255) : null,
                'message' => $this->redact($data['message'] ?? null),
                'request' => $settings->logPayloads ? $this->redact($this->truncate($data['request'] ?? null)) : null,
                'response' => $settings->logPayloads ? $this->truncate($data['response'] ?? null) : null,
                'dateCreated' => Db::prepareDateForDb(new DateTime()),
                'dateUpdated' => Db::prepareDateForDb(new DateTime()),
                'uid' => StringHelper::UUID(),
            ])->execute();

            return (int)$db->getLastInsertID(Craft::$app->getDb()->getSchema()->getRawTableName(Table::LOG));
        } catch (\Throwable $e) {
            // The log is diagnostics, never the point. Failing to write one must not take down
            // the push it was describing.
            Craft::warning('Twinsies could not write a log entry: ' . $e->getMessage(), __METHOD__);

            return null;
        }
    }

    /**
     * @return LogEntry[]
     */
    public function getEntries(array $criteria = [], int $limit = 100, int $offset = 0): array
    {
        return array_map(
            fn(array $row) => new LogEntry($row),
            $this->buildQuery($criteria)
                ->select(['id', 'action', 'level', 'statusCode', 'durationMs', 'documentId', 'orderId', 'summary', 'message', 'dateCreated', 'uid'])
                ->limit($limit)
                ->offset($offset)
                ->all()
        );
    }

    public function countEntries(array $criteria = []): int
    {
        return (int)$this->buildQuery($criteria)->count();
    }

    public function getEntryById(int $id): ?LogEntry
    {
        $row = (new Query())->from([Table::LOG])->where(['id' => $id])->one();

        return $row ? new LogEntry($row) : null;
    }

    /**
     * The distinct actions present, for the log filter.
     *
     * @return string[]
     */
    public function getActions(): array
    {
        return (new Query())
            ->select(['action'])
            ->distinct()
            ->from([Table::LOG])
            ->orderBy(['action' => SORT_ASC])
            ->column();
    }

    /**
     * Drop entries older than the configured retention. Returns the number deleted.
     */
    public function prune(?int $days = null): int
    {
        $days ??= Plugin::getInstance()->getSettings()->getEffectiveLogRetentionDays();

        if ($days <= 0) {
            return 0;
        }

        $cutoff = (new DateTime())->modify("-{$days} days");

        return (int)Craft::$app->getDb()->createCommand()->delete(Table::LOG, [
            '<', 'dateCreated', Db::prepareDateForDb($cutoff),
        ])->execute();
    }

    public function clear(): int
    {
        return (int)Craft::$app->getDb()->createCommand()->delete(Table::LOG)->execute();
    }

    private function buildQuery(array $criteria): Query
    {
        $query = (new Query())
            ->from([Table::LOG])
            ->orderBy(['dateCreated' => SORT_DESC, 'id' => SORT_DESC]);

        if (!empty($criteria['action'])) {
            $query->andWhere(['action' => $criteria['action']]);
        }

        if (!empty($criteria['level'])) {
            $query->andWhere(['level' => $criteria['level']]);
        }

        if (!empty($criteria['documentId'])) {
            $query->andWhere(['documentId' => $criteria['documentId']]);
        }

        if (!empty($criteria['orderId'])) {
            $query->andWhere(['orderId' => $criteria['orderId']]);
        }

        return $query;
    }

    private function truncate(?string $payload): ?string
    {
        if ($payload === null || $payload === '') {
            return null;
        }

        if (strlen($payload) <= self::MAX_PAYLOAD) {
            return $payload;
        }

        // `mb_strcut`, not `substr`: a cut through the middle of a multibyte character is invalid
        // UTF-8, which MySQL refuses — and the log entry for the failure being logged is lost.
        return mb_strcut($payload, 0, self::MAX_PAYLOAD, 'UTF-8') . "\n…[truncated]";
    }

    /**
     * Keep the access token out of the log.
     *
     * Request payloads are stored so a merchant can see what was sent, and the SOAP envelope
     * carries a bearer token for their entire administration. A CP user allowed to read the log
     * is not necessarily someone who should be able to lift that token out of it.
     */
    public function redact(?string $payload): ?string
    {
        if ($payload === null) {
            return null;
        }

        // The envelope's `<AccessToken>` element, and the `?token=` the validation endpoint takes
        // in its query string — which is what a transport error message quotes back.
        return preg_replace(
            ['#(<[^>:]*:?AccessToken[^>]*>)[^<]*(</)#i', '#([?&]token=)[^&\s"\'<>]+#i'],
            ['$1[redacted]$2', '$1[redacted]'],
            $payload
        );
    }
}
