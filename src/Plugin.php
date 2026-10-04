<?php

namespace justinholtweb\twinsies;

use Craft;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\commerce\elements\Order;
use craft\commerce\events\TransactionEvent;
use craft\commerce\services\OrderHistories;
use craft\commerce\records\Transaction as TransactionRecord;
use craft\commerce\services\Transactions;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\services\Gc;
use craft\services\UserPermissions;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use craft\web\View;
use justinholtweb\twinsies\models\Settings;
use justinholtweb\twinsies\jobs\ReconcilePayments;
use justinholtweb\twinsies\services\Api;
use justinholtweb\twinsies\services\Auth;
use justinholtweb\twinsies\services\Customers;
use justinholtweb\twinsies\services\Documents;
use justinholtweb\twinsies\services\Log;
use justinholtweb\twinsies\services\Mapping;
use justinholtweb\twinsies\services\Meta;
use justinholtweb\twinsies\services\Reconcile;
use justinholtweb\twinsies\services\Sync;
use justinholtweb\twinsies\twig\TwinsiesVariable;
use yii\base\Application;
use yii\base\Event;

/**
 * Twinsies — Twinfield integration for Craft Commerce.
 *
 * @property-read Auth $auth
 * @property-read Api $api
 * @property-read Meta $meta
 * @property-read Mapping $mapping
 * @property-read Customers $customers
 * @property-read Documents $documents
 * @property-read Sync $sync
 * @property-read Reconcile $reconcile
 * @property-read Log $log
 * @method Settings getSettings()
 */
class Plugin extends BasePlugin
{
    public const HANDLE = 'twinsies';

    /**
     * How often the automatic reconciliation sweep is allowed to queue itself, at the fastest.
     */
    private const RECONCILE_CACHE_KEY = 'twinsies:reconcile:next';

    public string $schemaVersion = '5.0.0';
    public bool $hasCpSettings = true;
    /**
     * Where admin changes are off, Craft renders the settings read-only rather than refusing the
     * page. The Connect link is not an input, so it survives — which is the point: the grant is a
     * database row and has to be made on the environment that uses it.
     */
    public bool $hasReadOnlyCpSettings = true;
    public bool $hasCpSection = true;

    /**
     * @inheritdoc
     */
    public static function config(): array
    {
        return [
            'components' => [
                'auth' => ['class' => Auth::class],
                'api' => ['class' => Api::class],
                'meta' => ['class' => Meta::class],
                'mapping' => ['class' => Mapping::class],
                'customers' => ['class' => Customers::class],
                'documents' => ['class' => Documents::class],
                'sync' => ['class' => Sync::class],
                'reconcile' => ['class' => Reconcile::class],
                'log' => ['class' => Log::class],
            ],
        ];
    }

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();

        $this->_registerTwigVariable();
        $this->_registerPermissions();
        $this->_registerCpRoutes();
        $this->_registerGarbageCollection();

        // The plugin can be installed while Commerce is disabled or mid-upgrade, and everything
        // below touches an order.
        if (!self::commerceIsReady()) {
            return;
        }

        $this->_registerOrderTriggers();
        $this->_registerRefundTrigger();
        if (Craft::$app->getRequest()->getIsCpRequest()) {
            $this->_registerOrderEditPanel();
        }

        $this->_registerReconcileSweep();
    }

    /**
     * Whether Twinsies has been set up far enough to be worth doing anything.
     *
     * An office and a grant that once existed. Not "can reach Twinfield this second" — that
     * distinction is what lets a momentary outage queue an order for retry while a fresh install
     * stays quiet.
     */
    public function isConfigured(): bool
    {
        return $this->getSettings()->getOffice() !== ''
            && $this->getAuth()->getConnection() !== null;
    }

    /**
     * Whether Commerce is present and enabled.
     */
    public static function commerceIsReady(): bool
    {
        return class_exists(\craft\commerce\Plugin::class)
            && Craft::$app->getPlugins()->isPluginEnabled('commerce');
    }

    public function getAuth(): Auth
    {
        return $this->get('auth');
    }

    public function getApi(): Api
    {
        return $this->get('api');
    }

    public function getMeta(): Meta
    {
        return $this->get('meta');
    }

    public function getMapping(): Mapping
    {
        return $this->get('mapping');
    }

    public function getCustomers(): Customers
    {
        return $this->get('customers');
    }

    public function getDocuments(): Documents
    {
        return $this->get('documents');
    }

    public function getSync(): Sync
    {
        return $this->get('sync');
    }

    public function getReconcile(): Reconcile
    {
        return $this->get('reconcile');
    }

    public function getLog(): Log
    {
        return $this->get('log');
    }

    /**
     * @inheritdoc
     */
    protected function createSettingsModel(): ?Model
    {
        return Craft::createObject(Settings::class);
    }

    /**
     * @inheritdoc
     */
    protected function settingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('twinsies/settings', [
            'plugin' => $this,
            'settings' => $this->getSettings(),
        ]);
    }

    /**
     * @inheritdoc
     */
    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();
        $item['label'] = Craft::t('twinsies', 'Twinsies');

        $user = Craft::$app->getUser();
        $subNav = [];

        // The Documents screens list customers, totals and addresses, so they need Commerce's own
        // order permission as well as Twinsies'.
        if ($user->checkPermission('twinsies-viewDocuments') && $user->checkPermission('commerce-manageOrders')) {
            $subNav['documents'] = [
                'label' => Craft::t('twinsies', 'Documents'),
                'url' => 'twinsies/documents',
            ];
        }

        if ($user->checkPermission('twinsies-manageMapping')) {
            $subNav['mapping'] = [
                'label' => Craft::t('twinsies', 'Mapping'),
                'url' => 'twinsies/mapping',
            ];
        }

        if ($user->checkPermission('twinsies-viewLog')) {
            $subNav['log'] = [
                'label' => Craft::t('twinsies', 'Log'),
                'url' => 'twinsies/log',
            ];
        }

        if ($user->getIsAdmin()) {
            $subNav['settings'] = [
                'label' => Craft::t('twinsies', 'Settings'),
                'url' => 'settings/plugins/twinsies',
            ];
        }

        if (!$subNav) {
            return null;
        }

        // The section's own URL is the Documents screen; someone who cannot see it lands on the
        // first screen they can.
        if (!isset($subNav['documents'])) {
            $item['url'] = reset($subNav)['url'];
        }

        $item['subnav'] = $subNav;

        return $item;
    }

    private function _registerTwigVariable(): void
    {
        Event::on(
            CraftVariable::class,
            CraftVariable::EVENT_INIT,
            static function(Event $event) {
                /** @var CraftVariable $variable */
                $variable = $event->sender;
                $variable->set('twinsies', TwinsiesVariable::class);
            }
        );
    }

    private function _registerPermissions(): void
    {
        Event::on(
            UserPermissions::class,
            UserPermissions::EVENT_REGISTER_PERMISSIONS,
            static function(RegisterUserPermissionsEvent $event) {
                $event->permissions[] = [
                    'heading' => Craft::t('twinsies', 'Twinsies'),
                    'permissions' => [
                        'twinsies-viewDocuments' => [
                            'label' => Craft::t('twinsies', 'View Twinfield documents'),
                            'nested' => [
                                'twinsies-pushDocuments' => [
                                    'label' => Craft::t('twinsies', 'Post documents to Twinfield'),
                                ],
                            ],
                        ],
                        'twinsies-manageMapping' => [
                            'label' => Craft::t('twinsies', 'Manage article and ledger mapping'),
                        ],
                        'twinsies-viewLog' => [
                            'label' => Craft::t('twinsies', 'View the Twinfield connection log'),
                        ],
                    ],
                ];
            }
        );
    }

    private function _registerCpRoutes(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            static function(RegisterUrlRulesEvent $event) {
                $event->rules['twinsies'] = 'twinsies/documents/index';
                $event->rules['twinsies/documents'] = 'twinsies/documents/index';
                $event->rules['twinsies/documents/<documentId:\d+>'] = 'twinsies/documents/detail';
                $event->rules['twinsies/mapping'] = 'twinsies/mapping/index';
                $event->rules['twinsies/log'] = 'twinsies/log/index';
                $event->rules['twinsies/log/<entryId:\d+>'] = 'twinsies/log/detail';
                // The OAuth redirect URI. Twinfield matches it exactly, so it has to be a real
                // control panel URL and not an action URL with a CSRF token in it.
                $event->rules['twinsies/auth/callback'] = 'twinsies/auth/callback';
            }
        );
    }

    /**
     * Order completion, and the status change that stands in for it when a merchant only wants
     * invoices for orders they have actually shipped.
     */
    private function _registerOrderTriggers(): void
    {
        Event::on(
            Order::class,
            Order::EVENT_AFTER_COMPLETE_ORDER,
            static function(Event $event) {
                /** @var Order $order */
                $order = $event->sender;
                Plugin::getInstance()->getSync()->handleOrder($order);
            }
        );

        Event::on(
            OrderHistories::class,
            OrderHistories::EVENT_ORDER_STATUS_CHANGE,
            static function(Event $event) {
                $order = $event->order ?? null;

                if ($order instanceof Order) {
                    Plugin::getInstance()->getSync()->handleOrder($order, true);
                }
            }
        );
    }

    /**
     * A Commerce refund becomes a credit document.
     *
     * Not `Payments::EVENT_AFTER_REFUND_TRANSACTION`: its `transaction` is the *parent* purchase,
     * it fires for a refund the gateway declined, and never for one settled later by webhook.
     * Every saved transaction comes through here instead, and only a successful refund counts.
     * The source key is the refund's own hash, so the save that marks a webhook-settled refund
     * successful a second time finds the row that is already there.
     */
    private function _registerRefundTrigger(): void
    {
        Event::on(
            Transactions::class,
            Transactions::EVENT_AFTER_SAVE_TRANSACTION,
            static function(TransactionEvent $event) {
                // Fail open, always — everything below sits inside the try, because this runs in
                // the request that refunded the customer.
                try {
                    $transaction = $event->transaction;

                    if (
                        $transaction->type !== TransactionRecord::TYPE_REFUND
                        || $transaction->status !== TransactionRecord::STATUS_SUCCESS
                        || !Plugin::getInstance()->isConfigured()
                    ) {
                        return;
                    }

                    $order = $transaction->getOrder();

                    if (!$order instanceof Order || !$order->id) {
                        return;
                    }

                    Plugin::getInstance()->getSync()->pushRefund(
                        $order,
                        $transaction,
                        Plugin::getInstance()->getSettings()->queuePush,
                    );
                } catch (\Throwable $e) {
                    // A refund that succeeded in the gateway must not be undone by an accounting
                    // system being unreachable.
                    Craft::error('Twinsies could not post a credit note: ' . $e->getMessage(), __METHOD__);
                }
            }
        );
    }

    /**
     * Apply the log retention setting whenever Craft collects garbage, rather than only when
     * someone presses the button. Request and response bodies carry customer names and addresses.
     */
    private function _registerGarbageCollection(): void
    {
        Event::on(Gc::class, Gc::EVENT_RUN, function() {
            try {
                $this->getLog()->prune();
            } catch (\Throwable $e) {
                Craft::warning('Twinsies could not prune its log: ' . $e->getMessage(), __METHOD__);
            }
        });
    }

    /**
     * Twinsies' panel on Commerce's own order edit screen.
     */
    private function _registerOrderEditPanel(): void
    {
        Craft::$app->getView()->hook('cp.commerce.order.edit.details', function(array &$context) {
            // A broken panel must never take Commerce's own order screen down with it.
            try {
                $order = $context['order'] ?? null;

                if (!$order instanceof Order || !$order->id) {
                    return null;
                }

                if (!Craft::$app->getUser()->checkPermission('twinsies-viewDocuments')) {
                    return null;
                }

                return Craft::$app->getView()->renderTemplate('twinsies/_order-panel', [
                    'order' => $order,
                    'documents' => $this->getSync()->getDocumentsForOrder($order->id),
                    'canPush' => Craft::$app->getUser()->checkPermission('twinsies-pushDocuments'),
                    'settings' => $this->getSettings(),
                ], View::TEMPLATE_MODE_CP);
            } catch (\Throwable $e) {
                Craft::warning('Twinsies could not render its order panel: ' . $e->getMessage(), __METHOD__);

                return null;
            }
        });
    }

    /**
     * Queue a reconciliation sweep now and then.
     *
     * Deliberately not a cron requirement: the merchants who need this most are the ones least
     * likely to have someone who will set one up. The throttle is a cache key rather than a
     * timestamp column so that several web nodes cannot all queue the same sweep.
     */
    private function _registerReconcileSweep(): void
    {
        Event::on(
            Application::class,
            Application::EVENT_AFTER_REQUEST,
            static function() {
                // This runs after every request, checkout included — the cache backend failing
                // here must not turn a completed payment into a 500.
                try {
                    $plugin = Plugin::getInstance();

                    if ($plugin === null || !$plugin->getSettings()->canReconcile()) {
                        return;
                    }

                    // `add()` only succeeds if the key is absent, which makes the throttle atomic.
                    if (!Craft::$app->getCache()->add(self::RECONCILE_CACHE_KEY, 1, max(300, $plugin->getSettings()->reconcileIntervalMinutes * 60))) {
                        return;
                    }

                    Craft::$app->getQueue()->push(new ReconcilePayments());
                } catch (\Throwable $e) {
                    Craft::warning('Twinsies could not queue a reconciliation sweep: ' . $e->getMessage(), __METHOD__);
                }
            }
        );
    }
}
