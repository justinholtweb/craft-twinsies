<?php

namespace justinholtweb\twinsies\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use justinholtweb\twinsies\Plugin;
use yii\console\ExitCode;

/**
 * Reading payment status back out of Twinfield.
 *
 * Run as `craft twinsies/reconcile/run`. The plugin also queues this itself on an interval, so a
 * cron entry is an optimisation rather than a requirement.
 */
class ReconcileController extends Controller
{
    /**
     * How many documents to check.
     */
    public ?int $limit = null;

    /**
     * @inheritdoc
     */
    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), $actionID === 'run' ? ['limit'] : []);
    }

    /**
     * Check open documents against Twinfield.
     */
    public function actionRun(): int
    {
        $settings = Plugin::getInstance()->getSettings();

        if (!$settings->reconcileEnabled) {
            $this->stderr("Reconciliation is switched off in the settings.\n", Console::FG_YELLOW);

            return ExitCode::CONFIG;
        }

        if (!$settings->postsFinal()) {
            $this->stderr(
                "Nothing to reconcile: documents are posted as "
                . ($settings->isTransactionMode() ? 'provisional transactions' : 'concept invoices')
                . ", which are not financial transactions yet.\n",
                Console::FG_YELLOW,
            );

            return ExitCode::CONFIG;
        }

        $result = Plugin::getInstance()->getReconcile()->run($this->limit);

        $this->stdout("Checked {$result['checked']}, {$result['paid']} newly paid, {$result['errors']} errored.\n",
            $result['errors'] > 0 ? Console::FG_YELLOW : Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * List the documents still waiting to be paid.
     */
    public function actionOpen(): int
    {
        $documents = Plugin::getInstance()->getReconcile()->findOpen(100);

        if (!$documents) {
            $this->stdout("No open documents.\n");

            return ExitCode::OK;
        }

        foreach ($documents as $document) {
            $this->stdout(sprintf(
                "  %-24s %-12s %s\n",
                $document->getReference(),
                $document->openValue !== null ? number_format($document->openValue, 2) : '?',
                $document->datePosted?->format('Y-m-d') ?? '',
            ));
        }

        $this->stdout("\n" . count($documents) . " open.\n");

        return ExitCode::OK;
    }
}
