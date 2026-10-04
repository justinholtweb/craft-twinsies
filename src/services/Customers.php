<?php

namespace justinholtweb\twinsies\services;

use Craft;
use craft\base\Component;
use craft\base\ElementInterface;
use craft\commerce\elements\Order;
use craft\db\Query;
use craft\elements\Address;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use DateTime;
use justinholtweb\twinsies\db\Table;
use justinholtweb\twinsies\helpers\Xml;
use justinholtweb\twinsies\models\Settings;
use justinholtweb\twinsies\Plugin;

/**
 * Craft customers as Twinfield debtors.
 *
 * Twinfield will not book an invoice to a customer that does not exist, and it does not create one
 * for you. So the debtor is created *before* the document is built — which also means this is the
 * one place that can turn a missing address into a clear error instead of a rejected invoice with
 * a Dutch message about `dimension`.
 *
 * Twinfield's address `field1`–`field6` carry no fixed meaning in the API. Twinsies uses
 * `field1`–`field3` for the three address lines and `field4` for a VAT number, which is the
 * convention Dutch installations use; nothing else reads them back.
 */
class Customers extends Component
{
    /**
     * Stands in for a debtor code Twinfield has not assigned yet, in a preview only. Never sent:
     * a real push writes the debtor first and uses the code Twinfield answers with.
     */
    public const PREVIEW_NEW_CODE = '(new)';

    public const DIMENSION_TYPE = 'DEB';

    public const SOURCE_USER = 'user';
    public const SOURCE_EMAIL = 'email';
    public const SOURCE_GUEST = 'guest';

    /**
     * The Twinfield debtor code to bill this order to, creating or updating the debtor as needed.
     *
     * @throws \RuntimeException when a debtor is required and cannot be established
     */
    public function resolveForOrder(Order $order): ?string
    {
        $settings = Plugin::getInstance()->getSettings();
        $office = $settings->getOffice();

        // A single catch-all debtor is a legitimate setup — a B2C shop with thousands of one-off
        // buyers does not want thousands of dimensions in its books.
        if ($settings->guestCustomerCode !== '' && !$order->getCustomer()) {
            return $settings->guestCustomerCode;
        }

        [$sourceType, $sourceKey] = $this->identify($order);
        $existing = $this->getStoredCode($office, $sourceType, $sourceKey);

        if (!$settings->syncCustomers) {
            // Not syncing means the merchant maps debtors by hand; a stored code still counts.
            return $existing ?: ($settings->guestCustomerCode ?: null);
        }

        if ($existing !== null && !$settings->updateExistingCustomers) {
            return $existing;
        }

        $code = $existing ?? $this->deriveCode($order, $sourceType, $sourceKey);
        $written = $this->writeDimension($order, $code);

        if ($written === null) {
            // Falling back to a known-good code beats failing the whole push over a debtor
            // Twinfield would not take.
            return $existing ?: ($settings->guestCustomerCode ?: null);
        }

        $this->rememberCode($office, $sourceType, $sourceKey, $written, $this->debtorName($order));

        return $written;
    }

    /**
     * The debtor code {@see resolveForOrder()} would use, without writing anything to Twinfield.
     *
     * For Preview XML. Where a push would create or update the debtor first, this says so in
     * `$warnings` and returns the code it would ask for — or {@see PREVIEW_NEW_CODE} where
     * Twinfield assigns the code itself, which no preview can know.
     *
     * @param string[] $warnings
     */
    public function peekForOrder(Order $order, array &$warnings = []): ?string
    {
        $settings = Plugin::getInstance()->getSettings();

        if ($settings->guestCustomerCode !== '' && !$order->getCustomer()) {
            return $settings->guestCustomerCode;
        }

        [$sourceType, $sourceKey] = $this->identify($order);
        $existing = $this->getStoredCode($settings->getOffice(), $sourceType, $sourceKey);

        if (!$settings->syncCustomers) {
            return $existing ?: ($settings->guestCustomerCode ?: null);
        }

        if ($existing !== null) {
            if ($settings->updateExistingCustomers) {
                $warnings[] = Craft::t('twinsies', 'Posting updates debtor {code} in Twinfield first.', ['code' => $existing]);
            }

            return $existing;
        }

        $code = $this->deriveCode($order, $sourceType, $sourceKey);

        if ($code === null) {
            $warnings[] = Craft::t('twinsies', 'Posting creates a new debtor in Twinfield first, and Twinfield chooses its code.');

            return self::PREVIEW_NEW_CODE;
        }

        $warnings[] = Craft::t('twinsies', 'Posting creates debtor {code} in Twinfield first.', ['code' => $code]);

        return $code;
    }

    /**
     * Create or update the Twinfield dimension, returning the code Twinfield ended up using.
     *
     * When `$code` is null Twinfield assigns the next code in the debtor range itself and reports
     * it in the response — which is why the response is read rather than assumed.
     */
    public function writeDimension(Order $order, ?string $code): ?string
    {
        $settings = Plugin::getInstance()->getSettings();
        $api = Plugin::getInstance()->getApi();

        $doc = Xml::document('dimension');
        $root = $doc->documentElement;

        Xml::append($root, 'office', $settings->getOffice());
        Xml::append($root, 'type', self::DIMENSION_TYPE);

        // Omitting <code> entirely is what asks Twinfield to number the debtor. Sending it empty
        // is a different request, and one Twinfield rejects.
        if ($code !== null && $code !== '') {
            Xml::append($root, 'code', $code);
        }

        Xml::append($root, 'name', mb_substr($this->debtorName($order), 0, 40));

        $financials = Xml::container($root, 'financials');
        Xml::append($financials, 'duedays', (string)max(0, $settings->dueDays));
        Xml::append($financials, 'payavailable', 'false');
        Xml::append($financials, 'ebilling', 'false');

        $address = $order->getBillingAddress() ?? $order->getShippingAddress();

        if ($address !== null) {
            $addresses = Xml::container($root, 'addresses');
            $addressEl = Xml::container($addresses, 'address', [
                'id' => '1',
                'type' => 'invoice',
                'default' => 'true',
            ]);

            Xml::append($addressEl, 'name', mb_substr($this->debtorName($order), 0, 40));
            Xml::append($addressEl, 'contact', mb_substr((string)($address->fullName ?: ''), 0, 40));
            Xml::append($addressEl, 'country', $address->countryCode);
            Xml::append($addressEl, 'city', $address->locality);
            Xml::append($addressEl, 'postcode', $address->postalCode);
            Xml::append($addressEl, 'telephone', $this->phone($address));
            Xml::append($addressEl, 'email', $order->getEmail());
            Xml::append($addressEl, 'field1', $address->addressLine1);
            Xml::append($addressEl, 'field2', $address->addressLine2);
            Xml::append($addressEl, 'field3', $address->addressLine3 ?? null);
            Xml::append($addressEl, 'field4', $this->vatNumber($address, $order));
        }

        try {
            $response = $api->process(Xml::toString($doc), 'sync.customer', orderId: $order->id);
        } catch (\Throwable $e) {
            Craft::warning('Twinsies could not sync a Twinfield debtor: ' . $e->getMessage(), __METHOD__);

            return null;
        }

        if (!Xml::succeeded($response)) {
            Craft::warning('Twinfield rejected a debtor: ' . Xml::summariseErrors($response), __METHOD__);

            return null;
        }

        $dimension = Xml::first($response, 'dimension');

        if ($dimension === null) {
            return $code;
        }

        return Xml::childText($dimension, 'code') ?? $code;
    }

    /**
     * Read a debtor back out of Twinfield.
     *
     * @return array<string, string>|null
     */
    public function read(string $code): ?array
    {
        $response = Plugin::getInstance()->getApi()->read([
            'type' => 'dimensions',
            'dimtype' => self::DIMENSION_TYPE,
            'code' => $code,
        ], action: 'read.customer');

        if (!Xml::succeeded($response)) {
            return null;
        }

        $dimension = Xml::first($response, 'dimension');

        if ($dimension === null) {
            return null;
        }

        return [
            'code' => Xml::childText($dimension, 'code') ?? $code,
            'name' => Xml::childText($dimension, 'name') ?? '',
            'status' => $dimension->getAttribute('status') ?: 'active',
        ];
    }

    /**
     * Which Craft identity this order's debtor is keyed on.
     *
     * @return array{0: string, 1: string}
     */
    public function identify(Order $order): array
    {
        $customer = $order->getCustomer();

        if ($customer?->id) {
            return [self::SOURCE_USER, (string)$customer->id];
        }

        $email = trim((string)$order->getEmail());

        if ($email !== '') {
            return [self::SOURCE_EMAIL, mb_strtolower($email)];
        }

        return [self::SOURCE_GUEST, (string)$order->id];
    }

    public function getStoredCode(string $office, string $sourceType, string $sourceKey): ?string
    {
        $code = (new Query())
            ->select(['code'])
            ->from([Table::CUSTOMERS])
            ->where(['office' => $office, 'sourceType' => $sourceType, 'sourceKey' => $sourceKey])
            ->scalar();

        return $code !== false && $code !== null ? (string)$code : null;
    }

    /**
     * Remember which Twinfield debtor a Craft identity maps to.
     */
    public function rememberCode(string $office, string $sourceType, string $sourceKey, string $code, ?string $name = null): void
    {
        $db = Craft::$app->getDb();
        $now = Db::prepareDateForDb(new DateTime());

        $existingId = (new Query())
            ->select(['id'])
            ->from([Table::CUSTOMERS])
            ->where(['office' => $office, 'sourceType' => $sourceType, 'sourceKey' => $sourceKey])
            ->scalar();

        if ($existingId) {
            $db->createCommand()->update(Table::CUSTOMERS, [
                'code' => $code,
                'name' => $name !== null ? mb_substr($name, 0, 255) : null,
                'dateSynced' => $now,
                'dateUpdated' => $now,
            ], ['id' => $existingId])->execute();

            return;
        }

        $db->createCommand()->insert(Table::CUSTOMERS, [
            'office' => $office,
            'sourceType' => $sourceType,
            'sourceKey' => mb_substr($sourceKey, 0, 255),
            'code' => $code,
            'name' => $name !== null ? mb_substr($name, 0, 255) : null,
            'dateSynced' => $now,
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => StringHelper::UUID(),
        ])->execute();
    }

    public function forget(string $office, string $sourceType, string $sourceKey): int
    {
        return (int)Craft::$app->getDb()->createCommand()
            ->delete(Table::CUSTOMERS, ['office' => $office, 'sourceType' => $sourceType, 'sourceKey' => $sourceKey])
            ->execute();
    }

    public function countMapped(?string $office = null): int
    {
        $office ??= Plugin::getInstance()->getSettings()->getOffice();

        return (int)(new Query())->from([Table::CUSTOMERS])->where(['office' => $office])->count();
    }

    /**
     * The debtor code this order would get, before Twinfield sees it.
     *
     * Null means "let Twinfield number it", which is the default: a code derived from a Craft user
     * ID collides the moment the merchant also enters customers by hand, and Twinfield's own
     * numbering never does.
     */
    public function deriveCode(Order $order, string $sourceType, string $sourceKey): ?string
    {
        $settings = Plugin::getInstance()->getSettings();

        $raw = match ($settings->customerCodeStrategy) {
            Settings::CODE_USER_ID => $sourceType === self::SOURCE_USER ? $sourceKey : null,
            Settings::CODE_EMAIL => $sourceType === self::SOURCE_EMAIL ? $sourceKey : (string)$order->getEmail(),
            Settings::CODE_TEMPLATE => $this->renderTemplate($settings->customerCodeTemplate, $order),
            default => null,
        };

        if ($raw === null || trim($raw) === '') {
            return null;
        }

        return $this->normaliseCode($settings->customerCodePrefix . $raw);
    }

    /**
     * Twinfield dimension codes are up to 16 characters of `A-Z0-9`. An email address is neither,
     * so it is hashed rather than mangled — a truncated address would collide between
     * `alexander@…` and `alexandra@…` on the same day the shop got both.
     */
    public function normaliseCode(string $raw): string
    {
        $clean = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $raw) ?? '');

        if ($clean === '') {
            return 'WEB' . strtoupper(substr(sha1($raw), 0, 10));
        }

        if (strlen($clean) <= 16) {
            return $clean;
        }

        // Keep a readable prefix and make the rest unique.
        return substr($clean, 0, 8) . strtoupper(substr(sha1($raw), 0, 8));
    }

    /**
     * The debtor's name, for the dimension and its address.
     *
     * Not `displayName()`: `craft\base\Component` declares that **static**, and redeclaring it
     * as an instance method is a compile error that fires when the class is autoloaded — with a
     * stack trace pointing nowhere near the caller.
     */
    private function debtorName(Order $order): string
    {
        $address = $order->getBillingAddress() ?? $order->getShippingAddress();

        foreach ([$address?->organization, $address?->fullName, $order->getEmail()] as $candidate) {
            $candidate = trim((string)$candidate);

            if ($candidate !== '') {
                return $candidate;
            }
        }

        return 'Order ' . $order->reference;
    }

    private function phone(Address $address): ?string
    {
        return $this->customFieldValue($address, Plugin::getInstance()->getSettings()->phoneFieldHandle, 32);
    }

    private function vatNumber(Address $address, Order $order): ?string
    {
        $handle = Plugin::getInstance()->getSettings()->vatNumberFieldHandle;

        // The VAT number is as likely to live on the user as on the address, depending on how the
        // shop models B2B customers. Whichever answers first wins.
        foreach ([$address, $order->getCustomer()] as $source) {
            if ($source === null) {
                continue;
            }

            $value = $this->customFieldValue($source, $handle, 36);

            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    /**
     * Read a custom field, asking the field layout first.
     *
     * `getFieldValue()` *throws* on a handle the element's layout does not have, so a merchant
     * typo in the settings would otherwise take down every push with an exception about an
     * unknown field rather than simply leaving the address line empty.
     */
    private function customFieldValue(ElementInterface $element, string $handle, int $maxLength): ?string
    {
        $handle = trim($handle);

        if ($handle === '') {
            return null;
        }

        if ($element->getFieldLayout()?->getFieldByHandle($handle) === null) {
            return null;
        }

        $value = $element->getFieldValue($handle);

        if (!is_scalar($value)) {
            return null;
        }

        $value = trim((string)$value);

        return $value !== '' ? mb_substr($value, 0, $maxLength) : null;
    }

    private function renderTemplate(string $template, Order $order): ?string
    {
        if (trim($template) === '') {
            return null;
        }

        try {
            return trim(Craft::$app->getView()->renderObjectTemplate($template, $order));
        } catch (\Throwable $e) {
            Craft::warning('Twinsies could not render the customer code template: ' . $e->getMessage(), __METHOD__);

            return null;
        }
    }
}
