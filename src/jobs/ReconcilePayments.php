<?php

namespace justinholtweb\twinsies\jobs;

use Craft;
use craft\queue\BaseJob;
use justinholtweb\twinsies\Plugin;

/**
 * Ask Twinfield which open documents have been paid.
 */
class ReconcilePayments extends BaseJob
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
     * @inheritdoc
     */
    protected function defaultDescription(): ?string
    {
        return Craft::t('twinsies', 'Checking Twinfield for payments');
    }
}
