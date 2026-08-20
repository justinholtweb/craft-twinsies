<?php

namespace justinholtweb\twinsies\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use justinholtweb\twinsies\Plugin;
use yii\console\ExitCode;

/**
 * Checking the Twinfield connection without a browser.
 *
 * Connecting itself is deliberately not here: the OAuth code flow needs a browser, and a console
 * command that pretended otherwise would only be able to offer credential-stuffing.
 */
class AuthController extends Controller
{
    /**
     * Show what Twinsies knows about the connection.
     */
    public function actionStatus(): int
    {
        $plugin = Plugin::getInstance();
        $auth = $plugin->getAuth();
        $connection = $auth->getConnection();

        if ($connection === null) {
            $this->stdout("Not connected.\n", Console::FG_YELLOW);

            return ExitCode::OK;
        }

        if (!$connection->readable) {
            $this->stderr("The stored tokens cannot be decrypted — the security key has changed. Reconnect from the control panel.\n", Console::FG_RED);

            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("Connected\n", Console::FG_GREEN, Console::BOLD);
        $this->stdout('  Cluster:  ' . ($connection->clusterUrl ?: '(unknown)') . "\n");
        $this->stdout('  User:     ' . ($connection->twinfieldUser ?: '(unknown)') . "\n");
        $this->stdout('  Expires:  ' . ($connection->expiresAt?->format('Y-m-d H:i:s') ?? '(unknown)') . "\n");
        $this->stdout('  Office:   ' . ($plugin->getSettings()->getOffice() ?: '(not set)') . "\n");

        return ExitCode::OK;
    }

    /**
     * Make a real request and report what came back.
     */
    public function actionTest(): int
    {
        $result = Plugin::getInstance()->getMeta()->testConnection();

        $this->stdout($result['message'] . "\n", $result['ok'] ? Console::FG_GREEN : Console::FG_RED);

        return $result['ok'] ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
    }

    /**
     * List the offices this grant can reach.
     */
    public function actionOffices(): int
    {
        try {
            $offices = Plugin::getInstance()->getMeta()->getOffices(true);
        } catch (\Throwable $e) {
            $this->stderr($e->getMessage() . "\n", Console::FG_RED);

            return ExitCode::UNSPECIFIED_ERROR;
        }

        foreach ($offices as $code => $name) {
            $this->stdout(sprintf("  %-12s %s\n", $code, $name));
        }

        $this->stdout("\n" . count($offices) . " office(s).\n");

        return ExitCode::OK;
    }
}
