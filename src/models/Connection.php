<?php

namespace justinholtweb\twinsies\models;

use Craft;
use craft\base\Model;
use craft\helpers\DateTimeHelper;
use DateTime;

/**
 * The stored OAuth grant, with its tokens decrypted.
 *
 * Decryption is deliberately forgiving: a rotated `CRAFT_SECURITY_KEY` makes every stored token
 * unreadable, and the right answer to that is "you are disconnected, reconnect" rather than a
 * fatal on every request that touches Twinfield.
 */
class Connection extends Model
{
    public ?int $id = null;
    public ?string $clientIdHash = null;
    public ?string $accessToken = null;
    public ?string $refreshToken = null;
    public ?DateTime $expiresAt = null;
    public ?string $clusterUrl = null;
    public ?string $scope = null;
    public ?string $twinfieldUser = null;
    public ?int $connectedByUserId = null;
    public ?DateTime $dateCreated = null;
    public ?DateTime $dateUpdated = null;
    public ?string $uid = null;

    /**
     * Whether the stored tokens could actually be read back.
     */
    public bool $readable = true;

    public function __construct($config = [])
    {
        foreach (['accessToken', 'refreshToken'] as $key) {
            if (!empty($config[$key])) {
                $decrypted = self::decrypt($config[$key]);

                if ($decrypted === null) {
                    $config['readable'] = false;
                }

                $config[$key] = $decrypted;
            } else {
                $config[$key] = null;
            }
        }

        foreach (['expiresAt', 'dateCreated', 'dateUpdated'] as $key) {
            if (!empty($config[$key])) {
                $config[$key] = DateTimeHelper::toDateTime($config[$key]) ?: null;
            } else {
                $config[$key] = null;
            }
        }

        parent::__construct($config);
    }

    /**
     * The cluster short name, for display: `api.accounting.twinfield.com` reads as `accounting`.
     */
    public function getClusterName(): ?string
    {
        if (!$this->clusterUrl) {
            return null;
        }

        $host = parse_url($this->clusterUrl, PHP_URL_HOST) ?: $this->clusterUrl;

        return preg_replace('/^api\.|\.twinfield\.com$/', '', $host) ?: $host;
    }

    private static function decrypt(string $value): ?string
    {
        // Stored base64-encoded: `encryptByKey()` emits raw binary, which a utf8mb4 text column
        // will not accept. See `services\Auth::encrypt()`.
        $binary = base64_decode($value, true);

        if ($binary === false) {
            return null;
        }

        try {
            return Craft::$app->getSecurity()->decryptByKey($binary) ?: null;
        } catch (\Throwable) {
            // Security key rotated, or the row was copied between environments.
            return null;
        }
    }
}
