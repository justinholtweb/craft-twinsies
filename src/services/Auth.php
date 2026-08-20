<?php

namespace justinholtweb\twinsies\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use DateTime;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use justinholtweb\twinsies\db\Table;
use justinholtweb\twinsies\models\Connection;
use justinholtweb\twinsies\Plugin;

/**
 * The OAuth grant.
 *
 * Twinfield is a multi-cluster platform: every organisation lives on one of several clusters, and
 * a web service call to the wrong one fails with an access error rather than a redirect. The
 * cluster is not something a merchant can look up — it is a claim on the validated access token,
 * so obtaining it is part of authenticating and is cached alongside the grant.
 *
 * The grant lives in `{{%twinsies_auth}}` rather than plugin settings, encrypted with Craft's
 * security key. A Twinfield refresh token is valid for about 25 years and grants full access to a
 * company's accounts; project config is committed to version control.
 */
class Auth extends Component
{
    public const AUTHORIZE_URL = 'https://login.twinfield.com/auth/authentication/connect/authorize';
    public const TOKEN_URL = 'https://login.twinfield.com/auth/authentication/connect/token';
    public const VALIDATION_URL = 'https://login.twinfield.com/auth/authentication/connect/accesstokenvalidation';

    /**
     * `offline_access` is what makes the grant survive the hour an access token lasts;
     * `twf.organisationUser` is what makes the web services accept it at all.
     */
    public const SCOPES = 'openid twf.user twf.organisation twf.organisationUser offline_access';

    public const STATE_SESSION_KEY = 'twinsies.oauth.state';

    /**
     * Refresh this many seconds before the token actually expires, so a request that takes a
     * while to reach Twinfield does not arrive with a token that expired in flight.
     */
    private const EXPIRY_SKEW = 120;

    private ?Connection $_connection = null;
    private ?Client $_client = null;

    /**
     * The stored grant, or null when the site has never been connected.
     */
    public function getConnection(): ?Connection
    {
        if ($this->_connection !== null) {
            return $this->_connection;
        }

        $row = (new Query())->from([Table::AUTH])->orderBy(['id' => SORT_ASC])->one();

        if (!$row) {
            return null;
        }

        return $this->_connection = new Connection($row);
    }

    /**
     * Whether there is a usable grant for the currently configured client ID.
     */
    public function isConnected(): bool
    {
        $connection = $this->getConnection();

        if ($connection === null || $connection->refreshToken === null) {
            return false;
        }

        return $connection->clientIdHash === $this->clientIdHash();
    }

    /**
     * Where to send the merchant to authorise the app.
     *
     * The `state` is generated here and stashed in the session; the callback refuses to exchange
     * a code that arrives without it, which is what stops an attacker walking a logged-in admin
     * through an authorisation for *their* Twinfield organisation.
     */
    public function getAuthorizationUrl(): string
    {
        $settings = Plugin::getInstance()->getSettings();
        $state = StringHelper::UUID();

        $this->session()?->set(self::STATE_SESSION_KEY, $state);

        $query = http_build_query([
            'client_id' => $settings->getClientId(),
            'redirect_uri' => $settings->getRedirectUri(),
            'response_type' => 'code',
            'scope' => self::SCOPES,
            'state' => $state,
        ]);

        return self::AUTHORIZE_URL . '?' . $query;
    }

    /**
     * Exchange an authorisation code for a grant and store it.
     *
     * @throws \RuntimeException when the exchange or the validation fails
     */
    public function completeAuthorization(string $code, string $state): Connection
    {
        $expected = $this->session()?->get(self::STATE_SESSION_KEY);
        $this->session()?->remove(self::STATE_SESSION_KEY);

        if (!$expected || !hash_equals((string)$expected, $state)) {
            throw new \RuntimeException('The authorisation response did not match this session. Start the connection again.');
        }

        $settings = Plugin::getInstance()->getSettings();

        $token = $this->requestToken([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $settings->getRedirectUri(),
        ]);

        if (empty($token['refresh_token'])) {
            throw new \RuntimeException(
                'Twinfield returned no refresh token. The app registration is missing the `offline_access` scope.'
            );
        }

        return $this->store($token);
    }

    /**
     * A valid access token, refreshing the grant if needed.
     *
     * @throws \RuntimeException when the site is not connected or the refresh fails
     */
    public function getAccessToken(): string
    {
        $connection = $this->getConnection();

        if ($connection === null || $connection->refreshToken === null) {
            throw new \RuntimeException('Twinsies is not connected to Twinfield.');
        }

        if ($connection->clientIdHash !== $this->clientIdHash()) {
            throw new \RuntimeException(
                'The Twinfield client ID has changed since this site was connected. Reconnect from the settings screen.'
            );
        }

        if ($connection->accessToken !== null && !$this->isExpired($connection)) {
            return $connection->accessToken;
        }

        return $this->refresh()->accessToken ?? throw new \RuntimeException('Twinfield returned no access token.');
    }

    /**
     * The cluster base URL every web service call goes to, e.g. `https://api.accounting.twinfield.com`.
     */
    public function getClusterUrl(): string
    {
        $connection = $this->getConnection();

        if ($connection?->clusterUrl) {
            return rtrim($connection->clusterUrl, '/');
        }

        // No cluster cached yet — a refresh resolves one as part of validating the token.
        $refreshed = $this->refresh();

        if (!$refreshed->clusterUrl) {
            throw new \RuntimeException('Twinfield did not report a cluster URL for this access token.');
        }

        return rtrim($refreshed->clusterUrl, '/');
    }

    /**
     * Force a refresh, whatever the stored expiry says.
     *
     * Called on a 401 as well as on expiry: Twinfield can invalidate a token early, and the only
     * way to find out is to be told no.
     */
    public function refresh(): Connection
    {
        $connection = $this->getConnection();

        if ($connection === null || $connection->refreshToken === null) {
            throw new \RuntimeException('Twinsies is not connected to Twinfield.');
        }

        $token = $this->requestToken([
            'grant_type' => 'refresh_token',
            'refresh_token' => $connection->refreshToken,
        ]);

        // A refresh does not always return a new refresh token; keep the one we have when it
        // does not, or the next refresh has nothing to present.
        $token['refresh_token'] = $token['refresh_token'] ?? $connection->refreshToken;

        return $this->store($token);
    }

    /**
     * Validate an access token and read the cluster URL and user off it.
     *
     * Not `validate()`: `craft\base\Component` extends `yii\base\Model`, whose `validate()` has
     * an entirely different signature. Overriding it is a **compile** error that fires the moment
     * the class is autoloaded, nowhere near the call site.
     *
     * @return array<string, mixed>
     */
    public function validateAccessToken(string $accessToken): array
    {
        $response = $this->http()->request('GET', self::VALIDATION_URL, [
            'query' => ['token' => $accessToken],
            'http_errors' => false,
            'timeout' => Plugin::getInstance()->getSettings()->timeout,
        ]);

        $body = (string)$response->getBody();

        if ($response->getStatusCode() !== 200) {
            throw new \RuntimeException('Twinfield rejected the access token: ' . ($body ?: 'HTTP ' . $response->getStatusCode()));
        }

        $decoded = json_decode($body, true);

        if (!is_array($decoded)) {
            throw new \RuntimeException('Twinfield returned an unreadable token validation response.');
        }

        return $decoded;
    }

    /**
     * Forget the grant. The app authorisation itself still exists in Twinfield.
     */
    public function disconnect(): void
    {
        Craft::$app->getDb()->createCommand()->delete(Table::AUTH)->execute();
        $this->_connection = null;
    }

    /**
     * @param array<string, string> $params
     * @return array<string, mixed>
     */
    private function requestToken(array $params): array
    {
        $settings = Plugin::getInstance()->getSettings();

        if (!$settings->hasCredentials()) {
            throw new \RuntimeException('No Twinfield client ID and secret are configured.');
        }

        // Credentials go in the form body rather than an Authorization header. Twinfield's
        // identity server accepts both, but only the body form is exercised by every existing
        // client, and a Basic header is the sort of thing a reverse proxy quietly strips.
        $params['client_id'] = $settings->getClientId();
        $params['client_secret'] = $settings->getClientSecret();

        try {
            $response = $this->http()->request('POST', self::TOKEN_URL, [
                'form_params' => $params,
                'http_errors' => false,
                'timeout' => $settings->timeout,
            ]);
        } catch (RequestException $e) {
            throw new \RuntimeException('Could not reach Twinfield to exchange the token: ' . $e->getMessage(), 0, $e);
        }

        $body = (string)$response->getBody();
        $decoded = json_decode($body, true);

        if ($response->getStatusCode() !== 200 || !is_array($decoded) || empty($decoded['access_token'])) {
            $error = is_array($decoded)
                ? ($decoded['error_description'] ?? $decoded['error'] ?? $body)
                : $body;

            throw new \RuntimeException('Twinfield refused the token request: ' . ($error ?: 'HTTP ' . $response->getStatusCode()));
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $token
     */
    private function store(array $token): Connection
    {
        $accessToken = (string)$token['access_token'];
        $validation = $this->validateAccessToken($accessToken);

        $expiresAt = (new DateTime())->modify('+' . max(60, (int)($token['expires_in'] ?? 3600)) . ' seconds');

        $attributes = [
            'clientIdHash' => $this->clientIdHash(),
            'accessToken' => $this->encrypt($accessToken),
            'refreshToken' => $this->encrypt((string)$token['refresh_token']),
            'expiresAt' => Db::prepareDateForDb($expiresAt),
            'clusterUrl' => $validation['twf.clusterUrl'] ?? null,
            'scope' => is_array($token['scope'] ?? null) ? implode(' ', $token['scope']) : ($token['scope'] ?? self::SCOPES),
            'twinfieldUser' => $validation['twf.userCode'] ?? $validation['sub'] ?? null,
            'dateUpdated' => Db::prepareDateForDb(new DateTime()),
        ];

        $db = Craft::$app->getDb();
        $existingId = (new Query())->select(['id'])->from([Table::AUTH])->orderBy(['id' => SORT_ASC])->scalar();

        if ($existingId) {
            $db->createCommand()->update(Table::AUTH, $attributes, ['id' => $existingId])->execute();
        } else {
            $db->createCommand()->insert(Table::AUTH, $attributes + [
                'connectedByUserId' => Craft::$app->getUser()->getIdentity()?->id,
                'dateCreated' => Db::prepareDateForDb(new DateTime()),
                'uid' => StringHelper::UUID(),
            ])->execute();
        }

        $this->_connection = null;

        return $this->getConnection();
    }

    private function isExpired(Connection $connection): bool
    {
        if ($connection->expiresAt === null) {
            return true;
        }

        return $connection->expiresAt->getTimestamp() - self::EXPIRY_SKEW <= time();
    }

    /**
     * The session, or null when there is not one.
     *
     * A console request has no session at all — asking for it throws `MissingComponentException`.
     * Returning null instead means the console can still print an authorisation URL, and a
     * callback that somehow arrived without a session is *refused* rather than crashing.
     */
    private function session(): ?\craft\web\Session
    {
        $session = Craft::$app->has('session') ? Craft::$app->get('session', false) : null;

        return $session instanceof \craft\web\Session ? $session : null;
    }

    private function clientIdHash(): string
    {
        return hash('sha256', Plugin::getInstance()->getSettings()->getClientId());
    }

    /**
     * Encrypt a token for storage.
     *
     * Base64 on the way out is not decoration. `encryptByKey()` returns **raw binary**, and a
     * `text` column on a utf8mb4 connection rejects it outright — MySQL answers "Incorrect string
     * value", which arrives disguised as `SQLSTATE[22007] Invalid datetime format`.
     */
    private function encrypt(string $value): string
    {
        return base64_encode(Craft::$app->getSecurity()->encryptByKey($value));
    }

    /**
     * Swap the HTTP client, so the integration suite can script the token endpoint and the token
     * validation endpoint rather than reaching Twinfield's identity server for real.
     */
    public function setClient(?Client $client): void
    {
        $this->_client = $client;
    }

    private function http(): Client
    {
        return $this->_client ??= Craft::createGuzzleClient(['connect_timeout' => 10]);
    }
}
