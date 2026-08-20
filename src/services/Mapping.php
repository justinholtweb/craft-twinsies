<?php

namespace justinholtweb\twinsies\services;

use Craft;
use craft\base\Component;
use craft\commerce\elements\Variant;
use craft\commerce\models\LineItem;
use craft\commerce\models\OrderAdjustment;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use DateTime;
use justinholtweb\twinsies\db\Table;
use justinholtweb\twinsies\helpers\Amounts;
use justinholtweb\twinsies\helpers\Xml;
use justinholtweb\twinsies\models\ArticleMap;
use justinholtweb\twinsies\models\LineMapping;
use justinholtweb\twinsies\Plugin;

/**
 * Where a Commerce line lands in Twinfield.
 *
 * Two questions, both of which have to have an answer for every line or the document is rejected:
 * which article and revenue account it books to, and which VAT code applies. Resolution is a
 * fallback chain — this purchasable, then its product type, then a catch-all row, then the
 * settings defaults — and the chain is walked in exactly one place so the mapping screen's
 * "this is what would happen" preview cannot disagree with the push.
 */
class Mapping extends Component
{
    /**
     * Article and ledger account for an order line.
     */
    public function forLineItem(LineItem $lineItem): LineMapping
    {
        $purchasable = $lineItem->getPurchasable();
        $keys = [];

        if ($purchasable?->id) {
            $keys[] = ArticleMap::purchasableKey($purchasable->id);
        }

        $productType = $this->productTypeHandle($lineItem);

        if ($productType !== null) {
            $keys[] = ArticleMap::productTypeKey($productType);
        }

        $keys[] = ArticleMap::KEY_DEFAULT;

        $mapping = $this->firstMatch($keys);
        $settings = Plugin::getInstance()->getSettings();

        $article = $mapping?->article ?: $this->autoArticleCode($lineItem) ?: $settings->defaultArticle;

        return new LineMapping(
            article: $article ?: null,
            subarticle: $mapping?->subarticle ?: null,
            revenueGl: $mapping?->revenueGl ?: ($settings->defaultRevenueGl ?: null),
            vatCode: $mapping?->vatCode ?: null,
            source: $mapping?->mapKey ?? 'settings',
        );
    }

    /**
     * Article and ledger account for the shipping line.
     */
    public function forShipping(): LineMapping
    {
        $settings = Plugin::getInstance()->getSettings();
        $mapping = $this->firstMatch([ArticleMap::KEY_SHIPPING, ArticleMap::KEY_DEFAULT]);

        return new LineMapping(
            article: $mapping?->article ?: ($settings->shippingArticle ?: $settings->defaultArticle ?: null),
            subarticle: $mapping?->subarticle ?: null,
            revenueGl: $mapping?->revenueGl ?: ($settings->shippingGl ?: $settings->defaultRevenueGl ?: null),
            vatCode: $mapping?->vatCode ?: null,
            source: $mapping?->mapKey ?? 'settings',
        );
    }

    /**
     * Article and ledger account for an order-level discount line.
     */
    public function forDiscount(): LineMapping
    {
        $settings = Plugin::getInstance()->getSettings();
        $mapping = $this->firstMatch([ArticleMap::KEY_DISCOUNT, ArticleMap::KEY_DEFAULT]);

        return new LineMapping(
            article: $mapping?->article ?: ($settings->discountArticle ?: $settings->defaultArticle ?: null),
            subarticle: $mapping?->subarticle ?: null,
            revenueGl: $mapping?->revenueGl ?: ($settings->discountGl ?: $settings->defaultRevenueGl ?: null),
            vatCode: $mapping?->vatCode ?: null,
            source: $mapping?->mapKey ?? 'settings',
        );
    }

    // VAT
    // -------------------------------------------------------------------------

    /**
     * The Twinfield VAT code for a line, given the tax Commerce actually charged on it.
     *
     * Matching on the *charged* rate rather than the tax category is the default because it is the
     * one that stays correct: a Commerce tax category can be attached to several rates (a zone per
     * country), and a Dutch shop shipping to Belgium charges 21% under one category and 0% under
     * another. Booking both to the same Twinfield VAT code misstates the VAT return.
     */
    public function vatCodeFor(float $net, float $tax, ?string $taxCategoryHandle = null): ?string
    {
        $settings = Plugin::getInstance()->getSettings();

        if ($settings->vatSource === $settings::VAT_BY_CATEGORY && $taxCategoryHandle !== null) {
            foreach ($settings->vatCategoryMap as $row) {
                if (($row['category'] ?? '') === $taxCategoryHandle && ($row['vatCode'] ?? '') !== '') {
                    return $row['vatCode'];
                }
            }
        }

        // No tax at all is its own case, and it is not "0% matched no row". Zero-rated exports and
        // reverse-charged intra-EU sales both look like zero here and both need a real code.
        if (Amounts::equal($tax, 0.0)) {
            return $settings->zeroVatCode ?: $settings->defaultVatCode ?: null;
        }

        if ($settings->vatSource === $settings::VAT_BY_RATE && !Amounts::equal($net, 0.0)) {
            $rate = round(($tax / $net) * 100, 2);

            foreach ($settings->vatRateMap as $row) {
                if (($row['vatCode'] ?? '') === '') {
                    continue;
                }

                // Rates are compared numerically: a merchant types "21", "21.0" or "21%" and all
                // three mean the same thing.
                $mapped = (float)preg_replace('/[^0-9.\-]/', '', (string)($row['rate'] ?? ''));

                if (abs($mapped - $rate) < 0.05) {
                    return $row['vatCode'];
                }
            }
        }

        return $settings->defaultVatCode ?: null;
    }

    /**
     * The tax Commerce charged on one line item, and whether it was included in the price.
     *
     * @return array{tax: float, included: bool, categoryHandle: string|null}
     */
    public function taxFor(LineItem $lineItem): array
    {
        $tax = 0.0;
        $included = false;

        foreach ($lineItem->getAdjustments() as $adjustment) {
            if ($adjustment->type !== 'tax') {
                continue;
            }

            $tax += (float)$adjustment->amount;

            if ($adjustment->included) {
                $included = true;
            }
        }

        $categoryHandle = null;

        try {
            $categoryHandle = $lineItem->getTaxCategory()?->handle;
        } catch (\Throwable) {
            // A tax category that has since been deleted throws rather than returning null.
        }

        return ['tax' => Amounts::round($tax), 'included' => $included, 'categoryHandle' => $categoryHandle];
    }

    /**
     * The tax on an order-level adjustment such as shipping.
     */
    public function taxForAdjustments(array $adjustments, string $sourceType): float
    {
        $tax = 0.0;

        foreach ($adjustments as $adjustment) {
            /** @var OrderAdjustment $adjustment */
            if ($adjustment->type !== 'tax') {
                continue;
            }

            // Commerce records which adjustment a tax line taxed in the snapshot.
            $snapshot = $adjustment->sourceSnapshot ?? [];
            $taxable = $snapshot['taxable'] ?? null;

            if ($taxable !== null && !str_contains((string)$taxable, $sourceType)) {
                continue;
            }

            $tax += (float)$adjustment->amount;
        }

        return Amounts::round($tax);
    }

    // The mapping table
    // -------------------------------------------------------------------------

    /**
     * @return ArticleMap[]
     */
    public function getMaps(?string $office = null): array
    {
        $office ??= Plugin::getInstance()->getSettings()->getOffice();

        return array_map(
            static fn(array $row) => new ArticleMap($row),
            (new Query())
                ->select(['id', 'office', 'mapKey', 'article', 'subarticle', 'revenueGl', 'vatCode', 'label'])
                ->from([Table::ARTICLES])
                ->where(['office' => $office])
                ->orderBy(['mapKey' => SORT_ASC])
                ->all()
        );
    }

    public function getMap(string $mapKey, ?string $office = null): ?ArticleMap
    {
        $office ??= Plugin::getInstance()->getSettings()->getOffice();

        $row = (new Query())
            ->select(['id', 'office', 'mapKey', 'article', 'subarticle', 'revenueGl', 'vatCode', 'label'])
            ->from([Table::ARTICLES])
            ->where(['office' => $office, 'mapKey' => $mapKey])
            ->one();

        return $row ? new ArticleMap($row) : null;
    }

    /**
     * Save a mapping row, or delete it when every field has been cleared.
     */
    public function saveMap(ArticleMap $map): bool
    {
        if (!$map->validate()) {
            return false;
        }

        $office = $map->office ?: Plugin::getInstance()->getSettings()->getOffice();

        if ($map->isEmpty()) {
            $this->deleteMap($map->mapKey, $office);

            return true;
        }

        $db = Craft::$app->getDb();
        $now = Db::prepareDateForDb(new DateTime());

        $values = [
            'article' => $map->article ?: null,
            'subarticle' => $map->subarticle ?: null,
            'revenueGl' => $map->revenueGl ?: null,
            'vatCode' => $map->vatCode ?: null,
            'label' => $map->label ?: null,
            'dateUpdated' => $now,
        ];

        $existingId = (new Query())
            ->select(['id'])
            ->from([Table::ARTICLES])
            ->where(['office' => $office, 'mapKey' => $map->mapKey])
            ->scalar();

        if ($existingId) {
            $db->createCommand()->update(Table::ARTICLES, $values, ['id' => $existingId])->execute();
            $map->id = (int)$existingId;
        } else {
            $db->createCommand()->insert(Table::ARTICLES, $values + [
                'office' => $office,
                'mapKey' => $map->mapKey,
                'dateCreated' => $now,
                'uid' => StringHelper::UUID(),
            ])->execute();
        }

        $this->_maps = null;

        return true;
    }

    public function deleteMap(string $mapKey, ?string $office = null): int
    {
        $office ??= Plugin::getInstance()->getSettings()->getOffice();
        $this->_maps = null;

        return (int)Craft::$app->getDb()->createCommand()
            ->delete(Table::ARTICLES, ['office' => $office, 'mapKey' => $mapKey])
            ->execute();
    }

    // Auto-created articles
    // -------------------------------------------------------------------------

    /**
     * Create the Twinfield article behind a line's code, if it does not exist yet.
     *
     * Twinfield's create-or-update semantics make this cheap but dangerous: posting an article
     * document for a code that already exists *overwrites* it. So this reads first, and only
     * writes when the read comes back empty — an accountant's hand-set price and VAT code on an
     * existing article are not ours to replace.
     */
    public function ensureArticle(string $code, string $name, ?string $vatCode = null, ?float $unitPrice = null): bool
    {
        $settings = Plugin::getInstance()->getSettings();

        if (!$settings->autoCreateArticles || trim($code) === '') {
            return false;
        }

        $api = Plugin::getInstance()->getApi();

        try {
            $existing = $api->read(['type' => 'article', 'code' => $code], action: 'read.article');

            if (Xml::succeeded($existing) && Xml::first($existing, 'article') !== null) {
                return false;
            }
        } catch (\Throwable) {
            // A read that fails is not permission to overwrite. Bail rather than guess.
            return false;
        }

        $doc = Xml::document('article');
        $root = $doc->documentElement;

        $header = Xml::container($root, 'header');
        Xml::append($header, 'office', $settings->getOffice());
        Xml::append($header, 'code', mb_substr($code, 0, 16));
        Xml::append($header, 'type', 'normal');
        Xml::append($header, 'name', mb_substr($name, 0, 40));
        Xml::append($header, 'shortname', mb_substr($name, 0, 20));
        Xml::append($header, 'unitnamesingular', 'item');
        Xml::append($header, 'unitnameplural', 'items');
        Xml::append($header, 'vatcode', $vatCode);
        Xml::append($header, 'allowchangevatcode', 'true');
        Xml::append($header, 'allowchangeunitsprice', 'true');
        Xml::append($header, 'allowdiscountorpremium', 'true');

        // Twinfield requires at least one sub-article; an article with no lines is rejected.
        $lines = Xml::container($root, 'lines');
        $line = Xml::container($lines, 'line', ['id' => '1']);
        Xml::append($line, 'name', mb_substr($name, 0, 40));
        Xml::append($line, 'shortname', mb_substr($name, 0, 20));
        Xml::append($line, 'subcode', mb_substr($code, 0, 16));
        Xml::append($line, 'units', '1');
        Xml::append($line, 'unitspriceexcl', $unitPrice !== null ? Amounts::precise($unitPrice) : null);

        $response = $api->process(Xml::toString($doc), 'create.article');

        return Xml::succeeded($response);
    }

    // -------------------------------------------------------------------------

    /** @var array<string, ArticleMap>|null */
    private ?array $_maps = null;

    /**
     * @param string[] $keys
     */
    private function firstMatch(array $keys): ?ArticleMap
    {
        $maps = $this->indexedMaps();

        foreach ($keys as $key) {
            if (isset($maps[$key])) {
                return $maps[$key];
            }
        }

        return null;
    }

    /**
     * @return array<string, ArticleMap>
     */
    private function indexedMaps(): array
    {
        if ($this->_maps !== null) {
            return $this->_maps;
        }

        $indexed = [];

        foreach ($this->getMaps() as $map) {
            $indexed[$map->mapKey] = $map;
        }

        return $this->_maps = $indexed;
    }

    private function productTypeHandle(LineItem $lineItem): ?string
    {
        $purchasable = $lineItem->getPurchasable();

        if (!$purchasable instanceof Variant) {
            return null;
        }

        // Commerce 5 exposes this directly on the variant; walking to the product and its type
        // means an extra element query per line, and throws for a variant whose product has gone.
        return $purchasable->getProductTypeHandle();
    }

    /**
     * The article code an unmapped purchasable would get, when auto-creation is on.
     */
    private function autoArticleCode(LineItem $lineItem): ?string
    {
        if (!Plugin::getInstance()->getSettings()->autoCreateArticles) {
            return null;
        }

        $sku = trim((string)$lineItem->getSku());

        // Twinfield codes are 16 characters and it does not truncate for you — it rejects.
        return $sku !== '' ? mb_substr($sku, 0, 16) : null;
    }
}
