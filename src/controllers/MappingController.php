<?php

namespace justinholtweb\twinsies\controllers;

use Craft;
use craft\commerce\Plugin as Commerce;
use craft\web\Controller;
use justinholtweb\twinsies\models\ArticleMap;
use justinholtweb\twinsies\Plugin;
use yii\web\Response;

/**
 * The mapping screen: which Twinfield article and ledger account each kind of line books to.
 */
class MappingController extends Controller
{
    /**
     * @inheritdoc
     */
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission('twinsies-manageMapping');

        return true;
    }

    public function actionIndex(): Response
    {
        $plugin = Plugin::getInstance();
        $meta = $plugin->getMeta();
        $mapping = $plugin->getMapping();

        $existing = [];

        foreach ($mapping->getMaps() as $map) {
            $existing[$map->mapKey] = $map;
        }

        // The rows a merchant is offered are the ones that always exist plus one per product type;
        // per-purchasable overrides are added from the search box rather than listed exhaustively,
        // because a catalogue of ten thousand variants is not a settings screen.
        $rows = [
            ArticleMap::KEY_DEFAULT => Craft::t('twinsies', 'Everything else'),
            ArticleMap::KEY_SHIPPING => Craft::t('twinsies', 'Shipping'),
            ArticleMap::KEY_DISCOUNT => Craft::t('twinsies', 'Discounts'),
        ];

        foreach (Commerce::getInstance()->getProductTypes()->getAllProductTypes() as $productType) {
            $rows[ArticleMap::productTypeKey($productType->handle)] = Craft::t('twinsies', 'Product type: {name}', [
                'name' => $productType->name,
            ]);
        }

        // Anything already mapped that is not one of the above — per-purchasable overrides.
        foreach ($existing as $key => $map) {
            if (!isset($rows[$key])) {
                $rows[$key] = $map->getSubjectLabel();
            }
        }

        return $this->renderTemplate('twinsies/mapping/_index', [
            'rows' => $rows,
            'existing' => $existing,
            'articles' => $meta->safely('articles'),
            'revenueAccounts' => $meta->safely('gl.pnl'),
            'vatCodes' => $meta->safely('vatcodes'),
            'settings' => $plugin->getSettings(),
            'connected' => $plugin->getAuth()->isConnected(),
        ]);
    }

    /**
     * Save every row on the mapping screen at once.
     */
    public function actionSave(): Response
    {
        $this->requirePostRequest();

        $posted = Craft::$app->getRequest()->getBodyParam('maps', []);
        $mapping = Plugin::getInstance()->getMapping();
        $office = Plugin::getInstance()->getSettings()->getOffice();
        $saved = 0;

        foreach ((array)$posted as $mapKey => $values) {
            $map = new ArticleMap([
                'office' => $office,
                'mapKey' => (string)$mapKey,
                'article' => trim((string)($values['article'] ?? '')) ?: null,
                'subarticle' => trim((string)($values['subarticle'] ?? '')) ?: null,
                'revenueGl' => trim((string)($values['revenueGl'] ?? '')) ?: null,
                'vatCode' => trim((string)($values['vatCode'] ?? '')) ?: null,
                'label' => trim((string)($values['label'] ?? '')) ?: null,
            ]);

            if ($mapping->saveMap($map)) {
                $saved++;
            }
        }

        Craft::$app->getSession()->setNotice(Craft::t('twinsies', '{count} mapping(s) saved.', ['count' => $saved]));

        return $this->redirectToPostedUrl();
    }

    /**
     * Article autocomplete for the mapping screen.
     */
    public function actionSearchArticles(): Response
    {
        $this->requireAcceptsJson();

        $pattern = (string)Craft::$app->getRequest()->getParam('q', '');

        try {
            return $this->asJson(['ok' => true, 'articles' => Plugin::getInstance()->getMeta()->searchArticles($pattern)]);
        } catch (\Throwable $e) {
            return $this->asJson(['ok' => false, 'message' => $e->getMessage(), 'articles' => []]);
        }
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();

        $mapKey = (string)Craft::$app->getRequest()->getRequiredBodyParam('mapKey');
        Plugin::getInstance()->getMapping()->deleteMap($mapKey);

        Craft::$app->getSession()->setNotice(Craft::t('twinsies', 'Mapping removed.'));

        return $this->redirectToPostedUrl();
    }
}
