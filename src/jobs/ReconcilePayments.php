<?php

namespace justinholtweb\twinsies\jobs;

use Craft;
use craft\queue\BaseJob;
use justinholtweb\twinsies\Plugin;
use yii\queue\RetryableJobInterface;

/**
 * Ask Twinfield which open documents have been paid.
 */
class ReconcilePayments extends BaseJob implements RetryableJobInterface
{
    public ?int $limit = null;

    /**
     * @inheritdoc
     */
    public function execute($queue): void
    {
        $this->setProgress($queue, 0.1, Craft::t('twinsies', 'Reading open documents'));

        $result = Plugin::getInstance()->getReconcile()->run($this->limit);

        $this->setProgress($queue, 1, Craft::t('twinsies', '{checked} checked, {paid} newly paid', [
            'checked' => $result['checked'],
            'paid' => $result['paid'],
        ]));
    }

    /**
     * A sweep reads up to `reconcileBatchSize` transactions one at a time; 300 seconds is not
     * enough for a large batch on a slow day.
     */
    public function getTtr(): int
    {
        return 3600;
    }

    /**
     * The next scheduled sweep is the retry.
     */
    public function canRetry($attempt, $error): bool
    {
        return false;
    }

    /**
     * @inheritdoc
     */
    protected function defaultDescription(): ?string
    {
        return Craft::t('twinsies', 'Checking Twinfield for payments');
    }
}
