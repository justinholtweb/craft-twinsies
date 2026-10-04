<?php

namespace justinholtweb\twinsies\controllers;

use Craft;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use justinholtweb\twinsies\Plugin;
use yii\web\Response;

/**
 * The OAuth dance.
 *
 * `callback` is registered as a plain control panel route rather than an action URL because
 * Twinfield matches the redirect URI byte for byte — an action URL carrying a CSRF token would be
 * a different URI on every request.
 */
class AuthController extends Controller
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

        // Deliberately not gated on `allowAdminChanges`: the grant is a database row, never
        // project config, so production — where admin changes are usually off — is exactly where
        // it has to be made.
        $this->requireAdmin(false);

        return true;
    }

    /**
     * Send the admin to Twinfield to authorise the app.
     */
    public function actionConnect(): Response
    {
        $settings = Plugin::getInstance()->getSettings();

        if (!$settings->hasCredentials()) {
            Craft::$app->getSession()->setError(Craft::t('twinsies', 'Add a Twinfield client ID and secret first.'));

            return $this->redirect('settings/plugins/twinsies');
        }

        return $this->redirect(Plugin::getInstance()->getAuth()->getAuthorizationUrl());
    }

    /**
     * Where Twinfield sends the admin back to.
     */
    public function actionCallback(): Response
    {
        $request = Craft::$app->getRequest();
        $session = Craft::$app->getSession();

        $error = $request->getQueryParam('error');

        if ($error) {
            $session->setError(Craft::t('twinsies', 'Twinfield refused the connection: {error}', [
                'error' => $request->getQueryParam('error_description') ?: $error,
            ]));

            return $this->redirect('settings/plugins/twinsies');
        }

        $code = (string)$request->getQueryParam('code');
        $state = (string)$request->getQueryParam('state');

        if ($code === '') {
            $session->setError(Craft::t('twinsies', 'Twinfield sent no authorisation code back.'));

            return $this->redirect('settings/plugins/twinsies');
        }

        try {
            Plugin::getInstance()->getAuth()->completeAuthorization($code, $state);
            // A new grant may be for a different organisation entirely, so nothing cached about
            // the last one can be trusted.
            Plugin::getInstance()->getMeta()->flush();

            $session->setNotice(Craft::t('twinsies', 'Connected to Twinfield.'));
        } catch (\Throwable $e) {
            $session->setError($e->getMessage());
        }

        return $this->redirect('settings/plugins/twinsies');
    }

    /**
     * Forget the stored grant.
     */
    public function actionDisconnect(): Response
    {
        $this->requirePostRequest();

        Plugin::getInstance()->getAuth()->disconnect();
        Plugin::getInstance()->getMeta()->flush();

        Craft::$app->getSession()->setNotice(Craft::t('twinsies', 'Disconnected from Twinfield.'));

        return $this->redirect(UrlHelper::cpUrl('settings/plugins/twinsies'));
    }
}
