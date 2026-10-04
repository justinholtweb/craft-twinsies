<?php

namespace justinholtweb\twinsies\controllers;

use Craft;
use craft\commerce\elements\Order;
use craft\web\Controller;
use justinholtweb\twinsies\helpers\Xml;
use justinholtweb\twinsies\models\Document;
use justinholtweb\twinsies\Plugin;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The Documents screen, and the actions behind the buttons on it and on the order edit panel.
 */
class DocumentsController extends Controller
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

        $this->requireCpRequest();

        // These screens list customers' emails, totals and addresses, and build documents that
        // carry them, so they need Commerce's own order permission as well as Twinsies'.
        $this->requirePermission('commerce-manageOrders');
        $this->requirePermission('twinsies-viewDocuments');

        return true;
    }

    public function actionIndex(): Response
    {
        $request = Craft::$app->getRequest();
        $sync = Plugin::getInstance()->getSync();

        $criteria = array_filter([
            'status' => $request->getQueryParam('status'),
            'kind' => $request->getQueryParam('kind'),
        ]);

        $page = max(1, (int)$request->getQueryParam('page', 1));
        $total = $sync->countDocuments($criteria);

        return $this->renderTemplate('twinsies/documents/_index', [
            'documents' => $sync->getDocuments($criteria, self::PAGE_SIZE, ($page - 1) * self::PAGE_SIZE),
            'counts' => $sync->getStatusCounts(),
            'criteria' => $criteria,
            'page' => $page,
            'pageSize' => self::PAGE_SIZE,
            'total' => $total,
            'canPush' => Craft::$app->getUser()->checkPermission('twinsies-pushDocuments'),
            'settings' => Plugin::getInstance()->getSettings(),
        ]);
    }

    /**
     * @throws NotFoundHttpException
     */
    public function actionDetail(int $documentId): Response
    {
        $document = Plugin::getInstance()->getSync()->getDocumentById($documentId);

        if ($document === null) {
            throw new NotFoundHttpException('Document not found.');
        }

        $order = $document->getOrder();
        $this->requireCanView($order);

        return $this->renderTemplate('twinsies/documents/_detail', [
            'document' => $document,
            'order' => $order,
            'entries' => Plugin::getInstance()->getLog()->getEntries(['documentId' => $documentId], 25),
            'canPush' => Craft::$app->getUser()->checkPermission('twinsies-pushDocuments'),
        ]);
    }

    /**
     * Build an order's document without sending it.
     *
     * This runs the *same* builder the push does, so what comes back is what Twinfield would get.
     * It runs as a dry run — the debtor and articles a push would create are named in the warnings
     * rather than written — but it still reads Twinfield, so it needs the same permission as a push
     * and a configured plugin.
     */
    public function actionPreview(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requirePermission('twinsies-pushDocuments');

        if (!Plugin::getInstance()->isConfigured()) {
            return $this->asJson(['ok' => false, 'message' => Craft::t('twinsies', 'Connect to Twinfield and choose an administration first.')]);
        }

        $orderId = (int)Craft::$app->getRequest()->getRequiredBodyParam('orderId');
        $kind = (string)Craft::$app->getRequest()->getBodyParam('kind', Document::KIND_INVOICE);
        $order = Order::find()->id($orderId)->isCompleted(true)->status(null)->one();

        if ($order === null) {
            return $this->asJson(['ok' => false, 'message' => Craft::t('twinsies', 'Order not found.')]);
        }

        $this->requireCanView($order);

        try {
            $built = Plugin::getInstance()->getDocuments()->build($order, $kind, dryRun: true);
        } catch (\Throwable $e) {
            return $this->asJson(['ok' => false, 'message' => $e->getMessage()]);
        }

        return $this->asJson([
            'ok' => true,
            'xml' => Xml::pretty($built->xml),
            'warnings' => $built->warnings,
            'total' => $built->total,
            'currency' => $built->currency,
            'customer' => $built->customerCode,
            'mode' => $built->mode,
        ]);
    }

    /**
     * Post an order to Twinfield now.
     */
    public function actionPush(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('twinsies-pushDocuments');

        $request = Craft::$app->getRequest();
        $sync = Plugin::getInstance()->getSync();

        $documentId = $request->getBodyParam('documentId');
        $orderId = $request->getBodyParam('orderId');
        $force = (bool)$request->getBodyParam('force', false);
        $queue = (bool)$request->getBodyParam('queue', false);

        if ($documentId) {
            $document = $sync->getDocumentById((int)$documentId);

            if ($document === null) {
                return $this->failure(Craft::t('twinsies', 'Document not found.'));
            }

            $this->requireCanView($document->getOrder());

            if ($queue) {
                $sync->enqueue($document);

                return $this->success(Craft::t('twinsies', 'Queued for posting.'));
            }

            $creditAmount = $document->kind === Document::KIND_CREDIT_NOTE ? $document->valueTotal : null;
            $ok = $sync->push($document, $force, $creditAmount);
            $fresh = $sync->getDocumentById($document->id);

            return $ok
                ? $this->success(Craft::t('twinsies', 'Posted to Twinfield as {reference}.', ['reference' => $fresh?->getReference() ?: '—']))
                : $this->failure($fresh?->lastError ?: Craft::t('twinsies', 'Twinfield rejected the document.'));
        }

        $order = $orderId ? Order::find()->id((int)$orderId)->status(null)->one() : null;

        if ($order === null) {
            return $this->failure(Craft::t('twinsies', 'Order not found.'));
        }

        $this->requireCanView($order);

        $document = $sync->pushOrder($order, $force, $queue);

        if ($queue) {
            return $this->success(Craft::t('twinsies', 'Queued for posting.'));
        }

        return $document->isSent()
            ? $this->success(Craft::t('twinsies', 'Posted to Twinfield as {reference}.', ['reference' => $document->getReference()]))
            : $this->failure($document->lastError ?: Craft::t('twinsies', 'Twinfield rejected the document.'));
    }

    /**
     * Queue every failed document that has retries left.
     */
    public function actionRetryFailed(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('twinsies-pushDocuments');

        $sync = Plugin::getInstance()->getSync();
        $queued = 0;

        foreach ($sync->findRetryable(200) as $document) {
            $sync->enqueue($document);
            $queued++;
        }

        return $this->success(Craft::t('twinsies', '{count} document(s) queued.', ['count' => $queued]));
    }

    /**
     * Record documents for completed orders that have none.
     */
    public function actionBackfill(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('twinsies-pushDocuments');

        $limit = (int)Craft::$app->getRequest()->getBodyParam('limit', 50);
        $sync = Plugin::getInstance()->getSync();
        $queued = 0;

        foreach ($sync->findUnposted(max(1, min(500, $limit))) as $order) {
            $sync->enqueue($sync->record($order));
            $queued++;
        }

        return $this->success(Craft::t('twinsies', '{count} order(s) queued for posting.', ['count' => $queued]));
    }

    /**
     * Forget a document row.
     *
     * This does not delete anything in Twinfield — it cannot, and pretending otherwise would be
     * worse than not offering it. What it does is let a failed or mis-mapped row be posted again
     * from scratch.
     */
    public function actionDelete(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('twinsies-pushDocuments');

        $documentId = (int)Craft::$app->getRequest()->getRequiredBodyParam('documentId');
        $document = Plugin::getInstance()->getSync()->getDocumentById($documentId);

        if ($document === null) {
            return $this->failure(Craft::t('twinsies', 'Document not found.'));
        }

        $this->requireCanView($document->getOrder());

        Plugin::getInstance()->getSync()->delete($document);

        return $this->success(Craft::t('twinsies', 'Document record removed. Nothing was changed in Twinfield.'));
    }

    /**
     * Commerce's per-order view permission, on top of the screen-level ones. A document whose
     * order is gone is allowed through: there is nothing left to protect, and it still needs
     * cleaning up.
     *
     * @throws ForbiddenHttpException
     */
    private function requireCanView(?Order $order): void
    {
        if ($order !== null && !Craft::$app->getElements()->canView($order, Craft::$app->getUser()->getIdentity())) {
            throw new ForbiddenHttpException('User not authorized to view this order.');
        }
    }

    private function success(string $message): Response
    {
        if (Craft::$app->getRequest()->getAcceptsJson()) {
            return $this->asJson(['success' => true, 'message' => $message]);
        }

        return $this->asSuccess($message);
    }

    private function failure(string $message): Response
    {
        if (Craft::$app->getRequest()->getAcceptsJson()) {
            return $this->asJson(['success' => false, 'message' => $message]);
        }

        Craft::$app->getSession()->setError($message);

        return $this->redirectToPostedUrl();
    }
}
