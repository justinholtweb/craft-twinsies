<?php

namespace justinholtweb\twinsies\models;

use craft\base\Model;
use craft\helpers\App;
use craft\helpers\UrlHelper;

/**
 * Twinsies settings.
 *
 * Nothing here is ever marked `required`. Craft validates plugin settings wholesale, so a single
 * required field makes the settings screen unsaveable on a fresh install — which is exactly when
 * the Twinfield credentials do not exist yet.
 *
 * The OAuth *grant* is deliberately not here. Settings are project config and project config is
 * committed to the repository; a refresh token with 25-year validity that grants full access to a
 * company's books must not end up in git. It lives in `{{%twinsies_auth}}`, encrypted.
 */
class Settings extends Model
{
    public const MODE_SALES_INVOICE = 'salesinvoice';
    public const MODE_TRANSACTION = 'transaction';

    public const TRIGGER_COMPLETE = 'complete';
    public const TRIGGER_STATUS = 'status';
    public const TRIGGER_MANUAL = 'manual';

    public const INVOICE_STATUS_CONCEPT = 'concept';
    public const INVOICE_STATUS_FINAL = 'final';

    public const DESTINY_TEMPORARY = 'temporary';
    public const DESTINY_FINAL = 'final';

    public const CODE_AUTO = 'auto';
    public const CODE_USER_ID = 'userId';
    public const CODE_EMAIL = 'email';
    public const CODE_TEMPLATE = 'template';

    public const VAT_BY_RATE = 'rate';
    public const VAT_BY_CATEGORY = 'category';

    // Connection
    // -------------------------------------------------------------------------

    /**
     * OAuth client ID of the Twinfield app. Env-parseable.
     */
    public string $clientId = '';

    /**
     * OAuth client secret. Env-parseable — put this in `.env`, not in project config.
     */
    public string $clientSecret = '';

    /**
     * The Twinfield office (administratie) code everything posts into. Env-parseable.
     */
    public string $office = '';

    /**
     * Seconds to wait on a Twinfield request before giving up.
     */
    public int $timeout = 30;

    /**
     * How many times a failed push is retried before the document is parked as failed.
     */
    public int $maxAttempts = 3;

    // What gets posted
    // -------------------------------------------------------------------------

    /**
     * `salesinvoice` posts a document Twinfield can print and send; `transaction` posts the
     * bookkeeping only. Shops without the Twinfield invoicing module can only use `transaction`.
     */
    public string $mode = self::MODE_SALES_INVOICE;

    /**
     * `complete` on order completion, `status` when an order reaches one of
     * {@see $triggerStatusHandles}, or `manual` for push-button only.
     */
    public string $trigger = self::TRIGGER_COMPLETE;

    /**
     * Order status handles that trigger a push, when `trigger` is `status`.
     *
     * @var string[]
     */
    public array $triggerStatusHandles = [];

    /**
     * Push through the queue rather than inline.
     *
     * On by default, and it matters more here than in most integrations: the push runs inside
     * order completion, which is inside the customer's payment request. A slow Twinfield must
     * never be able to hold up a checkout.
     */
    public bool $queuePush = true;

    // Sales invoice mode
    // -------------------------------------------------------------------------

    /**
     * Twinfield sales invoice type code, e.g. `FACTUUR`.
     */
    public string $invoiceType = 'FACTUUR';

    /**
     * `concept` leaves the invoice editable in Twinfield; `final` books it immediately.
     *
     * Concept is the default on purpose. A final invoice cannot be deleted, only credited, so a
     * misconfigured mapping discovered on day one is a support ticket rather than a mess in the
     * merchant's books.
     */
    public string $invoiceStatus = self::INVOICE_STATUS_CONCEPT;

    /**
     * Twinfield bank code the invoice asks to be paid into.
     */
    public string $bank = '';

    /**
     * One of `cash`, `bank`, `cheque`, `cashondelivery`, `da`.
     */
    public string $paymentMethod = 'bank';

    /**
     * Days added to the order date to get the due date. Overridden per customer by Twinfield's own
     * `duedays` when the debtor has one.
     */
    public int $dueDays = 14;

    /**
     * Object templates rendered against the order, for the text above and below the lines.
     */
    public string $headerText = '';
    public string $footerText = 'Order {{ object.reference }}';

    /**
     * Send Craft's order reference as the Twinfield invoice number instead of letting Twinfield
     * number the invoice itself.
     */
    public bool $sendOwnInvoiceNumber = false;

    // Transaction mode
    // -------------------------------------------------------------------------

    /**
     * Sales daybook code, e.g. `VRK`.
     */
    public string $daybook = 'VRK';

    /**
     * `temporary` posts provisionally (still deletable), `final` books it.
     */
    public string $destiny = self::DESTINY_TEMPORARY;

    /**
     * Balance sheet account for trade debtors — dim1 on the total line, e.g. `1300`.
     */
    public string $debtorGl = '';

    /**
     * Fall-back revenue account for lines with no more specific mapping, e.g. `8000`.
     */
    public string $defaultRevenueGl = '';

    /**
     * Revenue accounts for the two adjustment lines, when they should not land in the default.
     */
    public string $shippingGl = '';
    public string $discountGl = '';

    /**
     * Let Twinfield absorb up to two cents of VAT rounding rather than refusing the posting.
     */
    public bool $autoBalanceVat = true;

    /**
     * Post explicit `<line type="vat">` lines instead of letting Twinfield derive VAT from the
     * `vatcode` on each detail line. Only needed when the VAT account has to be chosen per line.
     */
    public bool $emitVatLines = false;

    /**
     * VAT account for those explicit lines.
     */
    public string $vatGl = '';

    /**
     * Send an explicit `yyyy/PP` period. Turn this off for offices on a shifted book year, so
     * Twinfield derives the period from the transaction date instead.
     */
    public bool $sendPeriod = true;

    // Customers
    // -------------------------------------------------------------------------

    /**
     * Create and update the Twinfield debtor before posting.
     */
    public bool $syncCustomers = true;

    /**
     * How a Twinfield debtor code is chosen: `auto` lets Twinfield number it, `userId` and
     * `email` derive it from Craft, `template` renders {@see $customerCodeTemplate}.
     */
    public string $customerCodeStrategy = self::CODE_AUTO;

    /**
     * Prefix for derived codes, e.g. `WEB`. Twinfield codes are at most 16 characters.
     */
    public string $customerCodePrefix = '';

    /**
     * Object template rendered against the order, when the strategy is `template`.
     */
    public string $customerCodeTemplate = '';

    /**
     * A single existing Twinfield debtor that every guest order books to. Leave empty to give
     * each guest their own debtor.
     */
    public string $guestCustomerCode = '';

    /**
     * Overwrite the name and address of a debtor that already exists.
     *
     * Off by default: an accountant who corrected a customer's VAT number in Twinfield does not
     * expect the next web order to put the wrong one back.
     */
    public bool $updateExistingCustomers = false;

    /**
     * Handle of the custom field on Craft addresses holding a phone number. Craft 5 moved
     * addresses out of Commerce and has no phone attribute of its own.
     */
    public string $phoneFieldHandle = '';

    /**
     * Handle of the custom field on Craft addresses or users holding a VAT number.
     */
    public string $vatNumberFieldHandle = '';

    // Articles and ledger accounts
    // -------------------------------------------------------------------------

    /**
     * Article every line falls back to when nothing more specific is mapped.
     */
    public string $defaultArticle = '';

    /**
     * Articles for the shipping and discount lines.
     */
    public string $shippingArticle = '';
    public string $discountArticle = '';

    /**
     * Create a Twinfield article for a purchasable that has no mapping, using its SKU as the code.
     */
    public bool $autoCreateArticles = false;

    /**
     * Object template rendered against the line item for the Twinfield line description. Twinfield
     * truncates this at 110 characters.
     */
    public string $lineDescriptionTemplate = '{{ object.description }}';

    /**
     * Put the SKU in the line's `freetext1`.
     */
    public bool $sendSkuAsFreetext = true;

    // VAT
    // -------------------------------------------------------------------------

    /**
     * Match Commerce tax to a Twinfield VAT code by `rate` (the percentage) or by `category`
     * (the Commerce tax category handle).
     */
    public string $vatSource = self::VAT_BY_RATE;

    /**
     * Rows of `['rate' => '21', 'vatCode' => 'VH']`.
     *
     * @var array<int, array{rate?: string, vatCode?: string}>
     */
    public array $vatRateMap = [];

    /**
     * Rows of `['category' => 'standard', 'vatCode' => 'VH']`.
     *
     * @var array<int, array{category?: string, vatCode?: string}>
     */
    public array $vatCategoryMap = [];

    /**
     * VAT code for lines that carry no tax at all — the usual case for shipping to a zero-rated
     * country, and for shops that do not charge VAT.
     */
    public string $zeroVatCode = '';

    /**
     * Last-resort VAT code when nothing matches.
     */
    public string $defaultVatCode = '';

    // Reconciliation
    // -------------------------------------------------------------------------

    /**
     * Read payment and match status back out of Twinfield.
     */
    public bool $reconcileEnabled = false;

    /**
     * Minutes between automatic reconciliation runs.
     */
    public int $reconcileIntervalMinutes = 360;

    /**
     * How far back to keep checking a document that is still open.
     */
    public int $reconcileLookbackDays = 180;

    /**
     * Documents read per run.
     */
    public int $reconcileBatchSize = 50;

    /**
     * Order status handle to move an order to once Twinfield reports it fully paid.
     */
    public string $paidOrderStatusHandle = '';

    /**
     * Record a Commerce payment transaction so the order counts as paid, not merely re-statused.
     *
     * Off by default. Commerce's paid state is normally owned by the payment gateway, and writing
     * a payment Commerce did not take is a claim about money that should be made deliberately.
     */
    public bool $markOrderPaid = false;

    // Credit notes
    // -------------------------------------------------------------------------

    /**
     * Post a credit document when a Commerce refund is taken.
     */
    public bool $creditNotesEnabled = true;

    /**
     * Sales invoice type for credit notes. Leave empty to reuse {@see $invoiceType} with negative
     * line amounts, which is what an office with no dedicated credit type needs.
     */
    public string $creditInvoiceType = '';

    /**
     * Daybook for credit transactions. Leave empty to reuse {@see $daybook} with the debit and
     * credit sides reversed.
     */
    public string $creditDaybook = '';

    // Log
    // -------------------------------------------------------------------------

    public bool $loggingEnabled = true;

    /**
     * Keep the request and response XML, not just the outcome. This is the difference between
     * "Twinfield rejected it" and knowing which field it rejected.
     */
    public bool $logPayloads = true;

    public int $logRetentionDays = 30;

    // -------------------------------------------------------------------------

    /**
     * @inheritdoc
     */
    public function defineRules(): array
    {
        return [
            [['timeout', 'maxAttempts', 'dueDays', 'reconcileIntervalMinutes', 'reconcileLookbackDays', 'reconcileBatchSize', 'logRetentionDays'], 'integer'],
            [['timeout'], 'integer', 'min' => 1, 'max' => 300],
            [['maxAttempts'], 'integer', 'min' => 1, 'max' => 10],
            [['reconcileBatchSize'], 'integer', 'min' => 1, 'max' => 500],
            [['reconcileIntervalMinutes'], 'integer', 'min' => 5],
            [['dueDays'], 'integer', 'min' => 0, 'max' => 365],
            [['mode'], 'in', 'range' => [self::MODE_SALES_INVOICE, self::MODE_TRANSACTION]],
            [['trigger'], 'in', 'range' => [self::TRIGGER_COMPLETE, self::TRIGGER_STATUS, self::TRIGGER_MANUAL]],
            [['invoiceStatus'], 'in', 'range' => [self::INVOICE_STATUS_CONCEPT, self::INVOICE_STATUS_FINAL]],
            [['destiny'], 'in', 'range' => [self::DESTINY_TEMPORARY, self::DESTINY_FINAL]],
            [['paymentMethod'], 'in', 'range' => ['cash', 'bank', 'cheque', 'cashondelivery', 'da']],
            [['vatSource'], 'in', 'range' => [self::VAT_BY_RATE, self::VAT_BY_CATEGORY]],
            [['customerCodeStrategy'], 'in', 'range' => [self::CODE_AUTO, self::CODE_USER_ID, self::CODE_EMAIL, self::CODE_TEMPLATE]],
            [['customerCodePrefix'], 'string', 'max' => 8],
            [['office', 'invoiceType', 'daybook', 'creditInvoiceType', 'creditDaybook', 'bank'], 'string', 'max' => 16],
            [['debtorGl', 'defaultRevenueGl', 'shippingGl', 'discountGl', 'vatGl'], 'string', 'max' => 32],
            [['vatRateMap', 'vatCategoryMap', 'triggerStatusHandles'], 'safe'],
        ];
    }

    // Resolved values
    // -------------------------------------------------------------------------

    public function getClientId(): string
    {
        return trim((string)App::parseEnv($this->clientId));
    }

    public function getClientSecret(): string
    {
        return trim((string)App::parseEnv($this->clientSecret));
    }

    public function getOffice(): string
    {
        return trim((string)App::parseEnv($this->office));
    }

    /**
     * Whether the OAuth app is configured at all. Distinct from being connected.
     */
    public function hasCredentials(): bool
    {
        return $this->getClientId() !== '' && $this->getClientSecret() !== '';
    }

    /**
     * The redirect URI to register with the Twinfield app. Twinfield matches it exactly, so this
     * is shown read-only on the settings screen for copying rather than typed by hand.
     */
    public function getRedirectUri(): string
    {
        return UrlHelper::cpUrl('twinsies/auth/callback');
    }

    public function isSalesInvoiceMode(): bool
    {
        return $this->mode === self::MODE_SALES_INVOICE;
    }

    public function isTransactionMode(): bool
    {
        return $this->mode === self::MODE_TRANSACTION;
    }

    /**
     * Whether a posted document is immediately irreversible in Twinfield.
     */
    public function postsFinal(): bool
    {
        return $this->isSalesInvoiceMode()
            ? $this->invoiceStatus === self::INVOICE_STATUS_FINAL
            : $this->destiny === self::DESTINY_FINAL;
    }

    /**
     * Reconciliation reads a *financial transaction*, and a document only becomes one when it is
     * booked. A concept invoice or a provisional transaction has nothing to reconcile against.
     */
    public function canReconcile(): bool
    {
        return $this->reconcileEnabled && $this->postsFinal();
    }

    public function getCreditInvoiceType(): string
    {
        return trim($this->creditInvoiceType) ?: trim($this->invoiceType);
    }

    public function getCreditDaybook(): string
    {
        return trim($this->creditDaybook) ?: trim($this->daybook);
    }

    /**
     * True when credit notes reuse the ordinary invoice type and therefore need negative amounts
     * to read as a credit.
     */
    public function creditNeedsNegativeAmounts(): bool
    {
        return trim($this->creditInvoiceType) === '';
    }

    public function getEffectiveLogRetentionDays(): int
    {
        return max(0, $this->logRetentionDays);
    }
}
