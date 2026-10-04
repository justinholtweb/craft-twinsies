<?php

namespace justinholtweb\twinsies\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\twinsies\Plugin;
use yii\web\Response;

/**
 * The buttons on the settings screen that are not "save".
 */
class SettingsController extends Controller
{
    /**
     * @inheritdoc
     */
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requireCpRequest();

        $this->requireAdmin(false);

        return true;
    }

    /**
     * Ask Twinfield whether the current configuration actually works.
     */
    public function actionTest(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        return $this->asJson(Plugin::getInstance()->getMeta()->testConnection());
    }

    /**
     * Drop the cached catalogues so the dropdowns are rebuilt from Twinfield.
     */
    public function actionRefreshMeta(): Response
    {
        $this->requirePostRequest();

        Plugin::getInstance()->getMeta()->flush();

        // Set as a flash too: the buttons that post here reload the page afterwards.
        $message = Craft::t('twinsies', 'Twinfield lists refreshed.');
        Craft::$app->getSession()->setSuccess($message);

        return $this->asSuccess($message);
    }
}
