<?php

namespace justinholtweb\twinsies\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\twinsies\helpers\Xml;
use justinholtweb\twinsies\Plugin;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The connection log.
 */
class LogController extends Controller
{
    private const PAGE_SIZE = 50;

    /**
     * @inheritdoc
     */
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission('twinsies-viewLog');

        return true;
    }

    public function actionIndex(): Response
    {
        $request = Craft::$app->getRequest();
        $log = Plugin::getInstance()->getLog();

        $criteria = array_filter([
            'action' => $request->getQueryParam('action'),
            'level' => $request->getQueryParam('level'),
        ]);

        $page = max(1, (int)$request->getQueryParam('page', 1));

        return $this->renderTemplate('twinsies/log/_index', [
            'entries' => $log->getEntries($criteria, self::PAGE_SIZE, ($page - 1) * self::PAGE_SIZE),
            'total' => $log->countEntries($criteria),
            'actions' => $log->getActions(),
            'criteria' => $criteria,
            'page' => $page,
            'pageSize' => self::PAGE_SIZE,
        ]);
    }

    /**
     * @throws NotFoundHttpException
     */
    public function actionDetail(int $entryId): Response
    {
        $entry = Plugin::getInstance()->getLog()->getEntryById($entryId);

        if ($entry === null) {
            throw new NotFoundHttpException('Log entry not found.');
        }

        return $this->renderTemplate('twinsies/log/_detail', [
            'entry' => $entry,
            'request' => $entry->request ? Xml::pretty($entry->request) : null,
            'response' => $entry->response ? Xml::pretty($entry->response) : null,
        ]);
    }

    public function actionClear(): Response
    {
        $this->requirePostRequest();
        $this->requireAdmin(false);

        $deleted = Plugin::getInstance()->getLog()->clear();

        Craft::$app->getSession()->setNotice(Craft::t('twinsies', '{count} log entries deleted.', ['count' => $deleted]));

        return $this->redirectToPostedUrl();
    }

    public function actionPrune(): Response
    {
        $this->requirePostRequest();
        $this->requireAdmin(false);

        $deleted = Plugin::getInstance()->getLog()->prune();

        Craft::$app->getSession()->setNotice(Craft::t('twinsies', '{count} old log entries deleted.', ['count' => $deleted]));

        return $this->redirectToPostedUrl();
    }
}
