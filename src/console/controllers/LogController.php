<?php

namespace justinholtweb\twinsies\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use justinholtweb\twinsies\Plugin;
use yii\console\ExitCode;

/**
 * Housekeeping for the connection log.
 */
class LogController extends Controller
{
    /**
     * Override the configured retention, in days.
     */
    public ?int $days = null;

    /**
     * @inheritdoc
     */
    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), $actionID === 'prune' ? ['days'] : []);
    }

    /**
     * Delete log entries older than the retention period.
     */
    public function actionPrune(): int
    {
        $deleted = Plugin::getInstance()->getLog()->prune($this->days);

        $this->stdout("Deleted {$deleted} log entries.\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Delete every log entry.
     */
    public function actionClear(): int
    {
        if (!$this->confirm('Delete the entire Twinfield connection log?')) {
            return ExitCode::OK;
        }

        $deleted = Plugin::getInstance()->getLog()->clear();

        $this->stdout("Deleted {$deleted} log entries.\n", Console::FG_GREEN);

        return ExitCode::OK;
    }
}
