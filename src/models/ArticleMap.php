<?php

namespace justinholtweb\twinsies\models;

use Craft;
use craft\base\Model;

/**
 * One row of the mapping table: what a Commerce line becomes in Twinfield.
 *
 * The key is a single string rather than a set of nullable columns. MySQL has no partial unique
 * index, so nullable `purchasableId`/`productTypeHandle` columns would let two rows claim the same
 * thing and leave the winner to whichever the query sorted first.
 */
class ArticleMap extends Model
{
    public const KEY_DEFAULT = 'default';
    public const KEY_SHIPPING = 'shipping';
    public const KEY_DISCOUNT = 'discount';

    public ?int $id = null;
    public string $office = '';
    public string $mapKey = '';
    public ?string $article = null;
    public ?string $subarticle = null;
    /** Twinfield dim1 — the revenue ledger account this line books to. */
    public ?string $revenueGl = null;
    public ?string $vatCode = null;
    public ?string $label = null;

    public static function purchasableKey(int $purchasableId): string
    {
        return "purchasable:{$purchasableId}";
    }

    public static function productTypeKey(string $handle): string
    {
        return "producttype:{$handle}";
    }

    /**
     * @inheritdoc
     */
    public function defineRules(): array
    {
        return [
            [['mapKey'], 'required'],
            [['article', 'subarticle'], 'string', 'max' => 32],
            [['revenueGl'], 'string', 'max' => 32],
            [['vatCode'], 'string', 'max' => 16],
        ];
    }

    /**
     * Whether this row says anything at all. An entirely blank row is a deletion, not a mapping.
     */
    public function isEmpty(): bool
    {
        return trim((string)$this->article) === ''
            && trim((string)$this->subarticle) === ''
            && trim((string)$this->revenueGl) === ''
            && trim((string)$this->vatCode) === '';
    }

    /**
     * What this row maps *from*, for the mapping screen.
     */
    public function getSubjectLabel(): string
    {
        if ($this->label) {
            return $this->label;
        }

        return match (true) {
            $this->mapKey === self::KEY_DEFAULT => Craft::t('twinsies', 'Everything else'),
            $this->mapKey === self::KEY_SHIPPING => Craft::t('twinsies', 'Shipping'),
            $this->mapKey === self::KEY_DISCOUNT => Craft::t('twinsies', 'Discounts'),
            str_starts_with($this->mapKey, 'producttype:') => Craft::t('twinsies', 'Product type: {handle}', [
                'handle' => substr($this->mapKey, 12),
            ]),
            str_starts_with($this->mapKey, 'purchasable:') => Craft::t('twinsies', 'Purchasable #{id}', [
                'id' => substr($this->mapKey, 12),
            ]),
            default => $this->mapKey,
        };
    }
}
