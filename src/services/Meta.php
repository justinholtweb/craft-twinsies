<?php

namespace justinholtweb\twinsies\services;

use Craft;
use craft\base\Component;
use justinholtweb\twinsies\Plugin;

/**
 * Twinfield's catalogues — offices, VAT codes, invoice types, daybooks, ledger accounts, articles.
 *
 * These are what turn the settings screen from a page of free-text boxes into dropdowns, which
 * matters more than it sounds: a mistyped ledger account is not rejected by Twinfield, it is
 * *created*, and the merchant finds a stray account in their chart of accounts weeks later.
 *
 * Every lookup but the office list goes through the Finder service, which answers each catalogue
 * with the same two-column code/name shape. Results are cached: the settings screen would
 * otherwise make six web service calls per page load, and Twinfield's fair use policy is not
 * generous.
 */
class Meta extends Component
{
    /**
     * Set once a catalogue has failed to load in this request; see {@see safely()}.
     */
    private bool $unreachable = false;

    public const CACHE_DURATION = 3600;

    /** Search the code and the name. */
    public const FIELD_CODE_OR_NAME = 0;
    public const FIELD_CODE = 1;
    public const FIELD_NAME = 2;

    /** Ledger accounts live on the balance sheet… */
    public const DIM_BALANCE = 'BAS';
    /** …or in profit and loss. Revenue accounts are PNL. */
    public const DIM_PROFIT_LOSS = 'PNL';
    /** Debtors — customers. */
    public const DIM_DEBTOR = 'DEB';

    private const CATALOGUES = ['offices', 'vatcodes', 'invoicetypes', 'daybooks', 'gl.bas', 'gl.pnl', 'banks', 'articles'];

    /**
     * The offices (administrations) this grant can reach.
     *
     * Asked *without* a company in the SOAP header. That is not an optimisation: a request that
     * names an office can only ever describe that office, so the one question a not-yet-configured
     * site needs to ask is the one question that must be asked anonymously.
     *
     * @return array<string, string> code => name
     */
    public function getOffices(bool $refresh = false): array
    {
        return $this->cached('offices', $refresh, function() {
            $doc = Plugin::getInstance()->getApi()->listing('offices', false);
            $offices = [];

            foreach ($doc->getElementsByTagName('office') as $office) {
                $code = trim($office->textContent);

                if ($code === '') {
                    continue;
                }

                $offices[$code] = trim($office->getAttribute('name')) ?: $code;
            }

            return $offices;
        });
    }

    /**
     * @return array<string, string> code => name
     */
    public function getVatCodes(bool $refresh = false): array
    {
        return $this->finderList('VAT', 'vatcodes', $refresh);
    }

    /**
     * Sales invoice types, e.g. `FACTUUR`.
     *
     * @return array<string, string>
     */
    public function getInvoiceTypes(bool $refresh = false): array
    {
        return $this->finderList('INV', 'invoicetypes', $refresh);
    }

    /**
     * Daybooks (transaction types), e.g. `VRK`.
     *
     * @return array<string, string>
     */
    public function getDaybooks(bool $refresh = false): array
    {
        return $this->finderList('TRS', 'daybooks', $refresh);
    }

    /**
     * Balance sheet accounts — where the debtor account lives.
     *
     * @return array<string, string>
     */
    public function getBalanceAccounts(bool $refresh = false): array
    {
        return $this->finderList('DIM', 'gl.bas', $refresh, ['dimtype' => self::DIM_BALANCE]);
    }

    /**
     * Profit and loss accounts — where revenue lines book to.
     *
     * @return array<string, string>
     */
    public function getRevenueAccounts(bool $refresh = false): array
    {
        return $this->finderList('DIM', 'gl.pnl', $refresh, ['dimtype' => self::DIM_PROFIT_LOSS]);
    }

    /**
     * @return array<string, string>
     */
    public function getBanks(bool $refresh = false): array
    {
        return $this->finderList('BNK', 'banks', $refresh);
    }

    /**
     * @return array<string, string>
     */
    public function getArticles(bool $refresh = false): array
    {
        return $this->finderList('ART', 'articles', $refresh);
    }

    /**
     * Free-text article search for the mapping screen. Never cached — the point is to find
     * something the cached list may not contain yet.
     *
     * @return array<int, array{code: string, name: string}>
     */
    public function searchArticles(string $pattern, int $limit = 25): array
    {
        $pattern = trim($pattern);

        if ($pattern === '') {
            return [];
        }

        $result = Plugin::getInstance()->getApi()->search(
            'ART',
            '*' . $pattern . '*',
            self::FIELD_CODE_OR_NAME,
            1,
            $limit,
            $this->officeOption(),
        );

        return array_values(array_filter(array_map(
            static fn(array $row) => isset($row[0]) ? ['code' => $row[0], 'name' => $row[1] ?? $row[0]] : null,
            $result['rows'],
        )));
    }

    /**
     * Turn a code => name map into the `[{label, value}]` shape Craft's form macros want, with an
     * optional blank first row so a setting can be left unset.
     *
     * @param array<string, string> $items
     * @return array<int, array{label: string, value: string}>
     */
    public function toOptions(array $items, ?string $blankLabel = null): array
    {
        $options = [];

        if ($blankLabel !== null) {
            $options[] = ['label' => $blankLabel, 'value' => ''];
        }

        foreach ($items as $code => $name) {
            $options[] = [
                'label' => $name === $code ? (string)$code : "{$name} ({$code})",
                'value' => (string)$code,
            ];
        }

        return $options;
    }

    /**
     * A catalogue, or an empty array if Twinfield cannot be reached.
     *
     * The settings screen calls this rather than the typed getters: a plugin whose settings page
     * 500s because the accounting system is down is a plugin that cannot be turned off.
     *
     * @return array<string, string>
     */
    public function safely(string $catalogue, bool $refresh = false): array
    {
        // The settings page asks for eight catalogues in a row, and each failure can cost
        // `timeout × maxAttempts`. Once one has failed in this request, the rest are not asked.
        if ($this->unreachable) {
            return [];
        }

        try {
            return match ($catalogue) {
                'offices' => $this->getOffices($refresh),
                'vatcodes' => $this->getVatCodes($refresh),
                'invoicetypes' => $this->getInvoiceTypes($refresh),
                'daybooks' => $this->getDaybooks($refresh),
                'gl.bas' => $this->getBalanceAccounts($refresh),
                'gl.pnl' => $this->getRevenueAccounts($refresh),
                'banks' => $this->getBanks($refresh),
                'articles' => $this->getArticles($refresh),
                default => [],
            };
        } catch (\Throwable $e) {
            Craft::info("Twinsies could not load the {$catalogue} catalogue: " . $e->getMessage(), __METHOD__);
            $this->unreachable = true;

            return [];
        }
    }

    /**
     * Drop every cached catalogue. Called when the office changes, when the grant is replaced,
     * and by the "Refresh from Twinfield" button.
     */
    public function flush(): void
    {
        $this->unreachable = false;

        foreach (self::CATALOGUES as $key) {
            Craft::$app->getCache()->delete($this->cacheKey($key));
        }
    }

    /**
     * Whether Twinsies can currently ask Twinfield anything at all.
     *
     * @return array{ok: bool, message: string}
     */
    public function testConnection(): array
    {
        $settings = Plugin::getInstance()->getSettings();

        if (!$settings->hasCredentials()) {
            return ['ok' => false, 'message' => Craft::t('twinsies', 'No Twinfield client ID and secret are configured.')];
        }

        if (!Plugin::getInstance()->getAuth()->isConnected()) {
            return ['ok' => false, 'message' => Craft::t('twinsies', 'Not connected to Twinfield yet.')];
        }

        try {
            $offices = $this->getOffices(true);
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }

        if (!$offices) {
            return ['ok' => false, 'message' => Craft::t('twinsies', 'Connected, but this Twinfield user can see no offices.')];
        }

        $office = $settings->getOffice();

        if ($office === '') {
            return [
                'ok' => false,
                'message' => Craft::t('twinsies', 'Connected, but no office is selected yet.'),
            ];
        }

        if (!isset($offices[$office])) {
            return [
                'ok' => false,
                'message' => Craft::t('twinsies', 'Connected, but office “{office}” is not one this Twinfield user can reach.', ['office' => $office]),
            ];
        }

        return [
            'ok' => true,
            'message' => Craft::t('twinsies', 'Connected to {name} ({office}).', ['name' => $offices[$office], 'office' => $office]),
        ];
    }

    /**
     * @param array<string, string> $options
     * @return array<string, string>
     */
    private function finderList(string $type, string $cacheKey, bool $refresh, array $options = []): array
    {
        return $this->cached($cacheKey, $refresh, function() use ($type, $options) {
            $items = [];
            $firstRow = 1;

            // The finder pages. Asking for everything in one call returns the first page anyway
            // and drops the rest, which shows up as a chart of accounts that stops at whatever
            // the merchant's hundredth account happens to be.
            do {
                $result = Plugin::getInstance()->getApi()->search(
                    $type,
                    '*',
                    self::FIELD_CODE_OR_NAME,
                    $firstRow,
                    100,
                    $options + $this->officeOption(),
                );

                foreach ($result['rows'] as $row) {
                    if (!isset($row[0]) || $row[0] === '') {
                        continue;
                    }

                    $items[$row[0]] = $row[1] ?? $row[0];
                }

                $firstRow += 100;
            } while ($firstRow <= $result['total'] && $firstRow <= 2000);

            return $items;
        });
    }

    /**
     * @return array<string, string>
     */
    private function officeOption(): array
    {
        $office = Plugin::getInstance()->getSettings()->getOffice();

        return $office !== '' ? ['office' => $office] : [];
    }

    /**
     * @template T
     * @param callable(): T $resolve
     * @return T
     */
    private function cached(string $key, bool $refresh, callable $resolve): mixed
    {
        $cache = Craft::$app->getCache();
        $cacheKey = $this->cacheKey($key);

        if (!$refresh) {
            $cached = $cache->get($cacheKey);

            if ($cached !== false) {
                return $cached;
            }
        }

        $value = $resolve();
        $cache->set($cacheKey, $value, self::CACHE_DURATION);

        return $value;
    }

    private function cacheKey(string $key): string
    {
        // The office is part of the key: switching offices must not serve the previous office's
        // chart of accounts, which would look plausible and be entirely wrong.
        return 'twinsies:meta:' . Plugin::getInstance()->getSettings()->getOffice() . ':' . $key;
    }
}
