<?php

namespace justinholtweb\twinsies\services;

use Craft;
use craft\base\Component;
use DOMDocument;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use justinholtweb\twinsies\errors\TwinfieldException;
use justinholtweb\twinsies\helpers\Xml;
use justinholtweb\twinsies\models\LogEntry;
use justinholtweb\twinsies\Plugin;

/**
 * The Twinfield transport.
 *
 * **Every** request to Twinfield goes through {@see request()}. That is the invariant this class
 * exists to hold: token refresh, cluster resolution, retries, the fair-use pause and the log entry
 * are written once, so no caller can accidentally talk to Twinfield without them.
 *
 * Twinfield's web services are SOAP 1.1, but this does not use `ext-soap`. There are exactly two
 * operations worth calling — `ProcessXmlString` and `Search` — both take scalars, and building
 * their envelopes by hand costs less than requiring an extension that plenty of managed PHP hosts
 * do not compile in. It also means the request that failed can be shown to the merchant verbatim.
 */
class Api extends Component
{
    public const NS_SOAP = 'http://schemas.xmlsoap.org/soap/envelope/';
    public const NS_TWINFIELD = 'http://www.twinfield.com/';

    public const SERVICE_PROCESS_XML = '/webservices/processxml.asmx';
    public const SERVICE_FINDER = '/webservices/finder.asmx';

    public const ACTION_PROCESS_XML = 'http://www.twinfield.com/ProcessXmlString';
    public const ACTION_SEARCH = 'http://www.twinfield.com/Search';

    /**
     * Twinfield's fair use guidance is to keep a parent element to 25 children. Larger documents
     * are accepted and then time out on their side, which surfaces as a request that succeeded
     * from PHP's point of view and posted nothing.
     */
    public const MAX_CHILDREN = 25;

    /**
     * Finder types.
     */
    public const FINDER_DIMENSIONS = 'DIM';
    public const FINDER_ARTICLES = 'ART';
    public const FINDER_INVOICES = 'IVT';

    private ?Client $_client = null;

    /**
     * Send a Twinfield XML document and get the parsed response back.
     *
     * The returned document is *not* checked for `result="1"` — a caller reading a customer that
     * does not exist wants the response, not an exception. {@see assertSucceeded()} is there for
     * the callers that do want one.
     *
     * @param string $action a short label for the log, e.g. `push.invoice`
     */
    public function process(string $xml, string $action = 'process', ?string $office = null, ?int $orderId = null, ?int $documentId = null): DOMDocument
    {
        $envelope = $this->buildProcessEnvelope($xml, $office);

        $response = $this->request(
            self::SERVICE_PROCESS_XML,
            self::ACTION_PROCESS_XML,
            $envelope,
            $action,
            $xml,
            $orderId,
            $documentId,
        );

        $result = $this->extractElementText($response, 'ProcessXmlStringResult');

        if ($result === null) {
            throw TwinfieldException::make('Twinfield returned a SOAP response with no ProcessXmlString result.');
        }

        return Xml::parse($result);
    }

    /**
     * Search Twinfield's finder.
     *
     * @param array<string, string|int> $options finder-specific option pairs, e.g. `['office' => '001']`
     * @return array{columns: string[], rows: array<int, string[]>, total: int, messages: string[]}
     */
    public function search(
        string $type,
        string $pattern,
        int $field = 0,
        int $firstRow = 1,
        int $maxRows = 100,
        array $options = [],
        ?string $office = null,
    ): array {
        $envelope = $this->buildSearchEnvelope($type, $pattern, $field, $firstRow, $maxRows, $options, $office);

        $response = $this->request(
            self::SERVICE_FINDER,
            self::ACTION_SEARCH,
            $envelope,
            "finder.{$type}",
            $envelope,
        );

        return $this->parseSearchResponse($response);
    }

    /**
     * Throw if the document Twinfield sent back reports a failure.
     *
     * @throws \RuntimeException
     */
    public function assertSucceeded(DOMDocument $doc, string $context = 'Twinfield rejected the document'): void
    {
        if (Xml::succeeded($doc)) {
            return;
        }

        throw TwinfieldException::make($context . ': ' . Xml::summariseErrors($doc), messages: Xml::collectErrors($doc));
    }

    /**
     * Split a list into documents Twinfield will actually finish processing.
     *
     * @template T
     * @param array<int, T> $items
     * @return array<int, array<int, T>>
     */
    public function chunk(array $items): array
    {
        return array_chunk($items, self::MAX_CHILDREN);
    }

    /**
     * Read a single object, e.g. `read(['type' => 'dimensions', 'dimtype' => 'DEB', 'code' => 'X'])`.
     *
     * @param array<string, string|null> $params
     */
    public function read(array $params, ?string $office = null, string $action = 'read'): DOMDocument
    {
        $settings = Plugin::getInstance()->getSettings();
        $doc = Xml::document('read');
        $root = $doc->documentElement;

        $params['office'] = $params['office'] ?? ($office ?: $settings->getOffice());

        // `type` has to come first: Twinfield reads it to decide how to interpret the rest, and a
        // read with the type further down returns an empty document rather than an error.
        if (isset($params['type'])) {
            Xml::append($root, 'type', $params['type']);
            unset($params['type']);
        }

        foreach ($params as $name => $value) {
            Xml::append($root, $name, $value);
        }

        return $this->process(Xml::toString($doc), $action, $office);
    }

    /**
     * List a catalogue, e.g. `list('offices')` or `list('vats')`.
     *
     * Passing `$office = false` sends the request with **no** company in the SOAP header, which is
     * the documented way — and the only way — to ask which offices the grant can even see.
     */
    public function listing(string $type, string|false|null $office = null, array $extra = []): DOMDocument
    {
        $doc = Xml::document('list');
        $root = $doc->documentElement;

        Xml::append($root, 'type', $type);

        foreach ($extra as $name => $value) {
            Xml::append($root, $name, $value);
        }

        return $this->process(
            Xml::toString($doc),
            "list.{$type}",
            $office === false ? '' : $office,
        );
    }

    // Envelopes
    // -------------------------------------------------------------------------

    /**
     * The SOAP envelope for a ProcessXmlString call.
     *
     * Built as a string rather than through DOM, because the one thing that has to be exactly
     * right here is escaping, and DOM will not do it. `DOMDocument::createTextNode()` escapes `<`
     * and `&` but leaves `>` alone — so a document containing `]]>` (a footer text a merchant
     * pasted, say) produces an envelope that is not well-formed XML at all, and Twinfield answers
     * with a fault about the request rather than about the invoice.
     *
     * A CDATA section has the same problem from the other direction: `]]>` closes it early and
     * silently truncates the document.
     */
    public function buildProcessEnvelope(string $xml, ?string $office = null): string
    {
        return $this->wrapInEnvelope(
            '<ProcessXmlString xmlns="' . self::NS_TWINFIELD . '">'
            . '<xmlRequest>' . self::escape($xml) . '</xmlRequest>'
            . '</ProcessXmlString>',
            $office,
        );
    }

    /**
     * @param array<string, string|int> $options
     */
    public function buildSearchEnvelope(
        string $type,
        string $pattern,
        int $field,
        int $firstRow,
        int $maxRows,
        array $options,
        ?string $office = null,
    ): string {
        $body = '<Search xmlns="' . self::NS_TWINFIELD . '">'
            . '<type>' . self::escape($type) . '</type>'
            . '<pattern>' . self::escape($pattern) . '</pattern>'
            . '<field>' . $field . '</field>'
            . '<firstRow>' . $firstRow . '</firstRow>'
            . '<maxRows>' . $maxRows . '</maxRows>';

        if ($options) {
            // The finder takes its options as an array of two-string arrays — name, then value.
            $body .= '<options>';

            foreach ($options as $name => $value) {
                $body .= '<ArrayOfString>'
                    . '<string>' . self::escape((string)$name) . '</string>'
                    . '<string>' . self::escape((string)$value) . '</string>'
                    . '</ArrayOfString>';
            }

            $body .= '</options>';
        }

        return $this->wrapInEnvelope($body . '</Search>', $office);
    }

    private function wrapInEnvelope(string $body, ?string $office): string
    {
        $settings = Plugin::getInstance()->getSettings();
        $accessToken = Plugin::getInstance()->getAuth()->getAccessToken();

        $header = '<AccessToken>' . self::escape($accessToken) . '</AccessToken>';

        // An empty office is not the same as no office. Omitting the element entirely is what
        // makes a company listing work; sending it empty gets "Company ontbreekt in request
        // header" back, in Dutch, regardless of the caller's language.
        $company = $office ?? $settings->getOffice();

        if ($company !== null && $company !== '') {
            $header .= '<CompanyCode>' . self::escape($company) . '</CompanyCode>';
        }

        return '<?xml version="1.0" encoding="utf-8"?>'
            . '<soap:Envelope xmlns:soap="' . self::NS_SOAP . '">'
            . '<soap:Header><Header xmlns="' . self::NS_TWINFIELD . '">' . $header . '</Header></soap:Header>'
            . '<soap:Body>' . $body . '</soap:Body>'
            . '</soap:Envelope>';
    }

    /**
     * Escape a value for an XML text node — including `>`, which is what DOM leaves out.
     */
    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    // Transport
    // -------------------------------------------------------------------------

    /**
     * POST an envelope, with one token refresh and a bounded retry.
     */
    private function request(
        string $service,
        string $soapAction,
        string $envelope,
        string $action,
        string $logRequest,
        ?int $orderId = null,
        ?int $documentId = null,
    ): DOMDocument {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();
        $url = $plugin->getAuth()->getClusterUrl() . $service;

        $attempts = 0;
        $refreshed = false;
        $started = microtime(true);
        $lastError = null;

        while (true) {
            $attempts++;

            try {
                $response = $this->client()->request('POST', $url, [
                    'headers' => [
                        'Content-Type' => 'text/xml; charset=utf-8',
                        'SOAPAction' => '"' . $soapAction . '"',
                        'Accept-Encoding' => 'gzip',
                    ],
                    'body' => $envelope,
                    'timeout' => $settings->timeout,
                    'http_errors' => false,
                ]);

                $status = $response->getStatusCode();
                $body = (string)$response->getBody();

                if ($status === 200) {
                    $doc = Xml::parse($body);

                    $plugin->getLog()->write($action, [
                        'level' => Xml::succeeded($doc) ? LogEntry::LEVEL_INFO : LogEntry::LEVEL_ERROR,
                        'statusCode' => $status,
                        'durationMs' => (int)round((microtime(true) - $started) * 1000),
                        'summary' => Xml::succeeded($doc) ? 'OK' : Xml::summariseErrors($doc),
                        'request' => $logRequest,
                        'response' => $body,
                        'orderId' => $orderId,
                        'documentId' => $documentId,
                    ]);

                    return $doc;
                }

                // 401 means the access token went stale early. Refresh once, rebuild the envelope
                // around the new token, and try again — but only once, so a genuinely revoked
                // grant fails instead of looping.
                if ($status === 401 && !$refreshed) {
                    $refreshed = true;
                    $plugin->getAuth()->refresh();
                    $envelope = $this->reissueEnvelope($envelope);
                    continue;
                }

                $lastError = $this->describeHttpFailure($status, $body);

                if (!$this->isRetryable($status) || $attempts >= $settings->maxAttempts) {
                    $plugin->getLog()->write($action, [
                        'level' => LogEntry::LEVEL_ERROR,
                        'statusCode' => $status,
                        'durationMs' => (int)round((microtime(true) - $started) * 1000),
                        'summary' => $lastError,
                        'request' => $logRequest,
                        'response' => $body,
                        'orderId' => $orderId,
                        'documentId' => $documentId,
                    ]);

                    throw TwinfieldException::make($lastError, retryable: $this->isRetryable($status));
                }
            } catch (ConnectException|RequestException $e) {
                $lastError = 'Could not reach Twinfield: ' . $e->getMessage();

                if ($attempts >= $settings->maxAttempts) {
                    $plugin->getLog()->write($action, [
                        'level' => LogEntry::LEVEL_ERROR,
                        'durationMs' => (int)round((microtime(true) - $started) * 1000),
                        'summary' => $lastError,
                        'request' => $logRequest,
                        'orderId' => $orderId,
                        'documentId' => $documentId,
                    ]);

                    throw TwinfieldException::make($lastError, retryable: true, previous: $e);
                }
            }

            // Linear rather than exponential: Twinfield's transient failures are load, and the
            // fair use policy would rather see a slow client than a burst of retries.
            usleep(min(5, $attempts) * 500_000);
        }
    }

    /**
     * Put a freshly refreshed access token into an envelope that was built with the old one.
     */
    private function reissueEnvelope(string $envelope): string
    {
        // A plain splice, not a regex replace: a JWT is full of characters that mean something in
        // a replacement string, and escaping them correctly is a worse problem than not having it.
        $open = '<AccessToken>';
        $close = '</AccessToken>';

        $start = strpos($envelope, $open);

        if ($start === false) {
            return $envelope;
        }

        $from = $start + strlen($open);
        $to = strpos($envelope, $close, $from);

        if ($to === false) {
            return $envelope;
        }

        $token = self::escape(Plugin::getInstance()->getAuth()->getAccessToken());

        return substr($envelope, 0, $from) . $token . substr($envelope, $to);
    }

    private function isRetryable(int $status): bool
    {
        return $status === 429 || $status >= 500;
    }

    private function describeHttpFailure(int $status, string $body): string
    {
        // A SOAP fault carries the real reason; the HTTP status alone is always 500.
        try {
            $doc = Xml::parse($body);

            foreach (['faultstring', 'Text', 'Reason'] as $tag) {
                $nodes = $doc->getElementsByTagName($tag);

                if ($nodes->length > 0) {
                    $text = trim($nodes->item(0)->textContent);

                    if ($text !== '') {
                        return "Twinfield returned HTTP {$status}: {$text}";
                    }
                }
            }
        } catch (\Throwable) {
            // Not XML — fall through to the raw body.
        }

        $snippet = trim(mb_substr(strip_tags($body), 0, 200));

        return "Twinfield returned HTTP {$status}" . ($snippet !== '' ? ": {$snippet}" : '.');
    }

    private function extractElementText(DOMDocument $doc, string $localName): ?string
    {
        $nodes = $doc->getElementsByTagNameNS(self::NS_TWINFIELD, $localName);

        if ($nodes->length === 0) {
            // Some proxies strip namespaces on the way back.
            $nodes = $doc->getElementsByTagName($localName);
        }

        if ($nodes->length === 0) {
            return null;
        }

        return $nodes->item(0)->textContent;
    }

    /**
     * @return array{columns: string[], rows: array<int, string[]>, total: int, messages: string[]}
     */
    private function parseSearchResponse(DOMDocument $doc): array
    {
        $messages = [];

        foreach ($doc->getElementsByTagNameNS(self::NS_TWINFIELD, 'MessageOfErrorCodes') as $message) {
            $text = trim($message->textContent);

            if ($text !== '') {
                $messages[] = $text;
            }
        }

        $columns = [];
        $rows = [];
        $total = 0;

        $data = $doc->getElementsByTagNameNS(self::NS_TWINFIELD, 'data')->item(0);

        if ($data instanceof \DOMElement) {
            $totalNode = $data->getElementsByTagNameNS(self::NS_TWINFIELD, 'TotalRows')->item(0);
            $total = $totalNode ? (int)$totalNode->textContent : 0;

            $columnsNode = $data->getElementsByTagNameNS(self::NS_TWINFIELD, 'Columns')->item(0);

            if ($columnsNode instanceof \DOMElement) {
                foreach ($columnsNode->getElementsByTagNameNS(self::NS_TWINFIELD, 'string') as $string) {
                    $columns[] = $string->textContent;
                }
            }

            $itemsNode = $data->getElementsByTagNameNS(self::NS_TWINFIELD, 'Items')->item(0);

            if ($itemsNode instanceof \DOMElement) {
                foreach ($itemsNode->getElementsByTagNameNS(self::NS_TWINFIELD, 'ArrayOfString') as $item) {
                    $row = [];

                    foreach ($item->getElementsByTagNameNS(self::NS_TWINFIELD, 'string') as $string) {
                        $row[] = $string->textContent;
                    }

                    $rows[] = $row;
                }
            }
        }

        return compact('columns', 'rows', 'total', 'messages');
    }

    /**
     * Swap the HTTP client.
     *
     * The integration suite drives the whole pipeline through a Guzzle mock handler this way, so
     * the SOAP envelope, Twinfield's `result="0"` error attributes, the 401-refresh-and-retry and
     * the retry backoff are all exercised for real rather than described.
     */
    public function setClient(?Client $client): void
    {
        $this->_client = $client;
    }

    private function client(): Client
    {
        return $this->_client ??= Craft::createGuzzleClient([
            'connect_timeout' => 10,
        ]);
    }
}
