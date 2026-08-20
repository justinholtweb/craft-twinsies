<?php

namespace justinholtweb\twinsies\helpers;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * Building and reading Twinfield XML.
 *
 * Twinfield's ProcessXml service answers every request — a create, a read, a list — with the same
 * document shape, and reports success by *attribute*: the root carries `result="1"` when the
 * posting went through, and any tag that failed carries `result="0"` plus `msg` and `msgtype`.
 * Every parent of a failed tag is marked `result="0"` too, so the root alone never tells you what
 * actually went wrong. {@see collectMessages()} walks the whole tree for that reason.
 */
abstract class Xml
{
    public const MSGTYPE_ERROR = 'error';
    public const MSGTYPE_WARNING = 'warning';

    /**
     * Parse a Twinfield response.
     *
     * `LIBXML_NONET` and the deliberate absence of `LIBXML_NOENT` are the whole XXE defence: an
     * accounting integration parses documents from a third party over the network, and entity
     * substitution is the one libxml behaviour that turns that into file disclosure.
     *
     * @throws \RuntimeException if the payload is not well-formed XML
     */
    public static function parse(string $xml): DOMDocument
    {
        $xml = trim($xml);

        if ($xml === '') {
            throw new \RuntimeException('Twinfield returned an empty response body.');
        }

        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        $doc = new DOMDocument();
        $loaded = $doc->loadXML($xml, LIBXML_NONET | LIBXML_NOCDATA);

        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded || $doc->documentElement === null) {
            $detail = $errors ? trim($errors[0]->message) : 'unknown parse error';
            throw new \RuntimeException("Twinfield returned a malformed XML response: {$detail}");
        }

        return $doc;
    }

    /**
     * Whether the document as a whole succeeded.
     *
     * A missing `result` attribute counts as success: read and list responses do not carry one,
     * and treating their absence as failure would reject every successful lookup.
     */
    public static function succeeded(DOMDocument $doc): bool
    {
        $root = $doc->documentElement;

        if ($root === null) {
            return false;
        }

        if (!$root->hasAttribute('result')) {
            return !self::hasFailedDescendant($root);
        }

        return $root->getAttribute('result') === '1';
    }

    /**
     * Every `msg` in the document, innermost first — those are the ones that name the actual
     * field. Each entry is `['type' => 'error'|'warning', 'field' => 'duedate', 'message' => '…']`.
     *
     * @return array<int, array{type: string, field: string, message: string}>
     */
    public static function collectMessages(DOMDocument $doc): array
    {
        $messages = [];

        if ($doc->documentElement === null) {
            return $messages;
        }

        self::walk($doc->documentElement, function(DOMElement $el) use (&$messages) {
            if (!$el->hasAttribute('msg')) {
                return;
            }

            $messages[] = [
                'type' => $el->getAttribute('msgtype') ?: self::MSGTYPE_ERROR,
                'field' => $el->nodeName,
                'message' => $el->getAttribute('msg'),
            ];
        });

        // Deepest first: the root and its ancestors inherit `result="0"`, but only the failing
        // leaf carries a message worth showing a merchant.
        return array_reverse($messages);
    }

    /**
     * The messages that are errors rather than warnings.
     *
     * @return array<int, array{type: string, field: string, message: string}>
     */
    public static function collectErrors(DOMDocument $doc): array
    {
        return array_values(array_filter(
            self::collectMessages($doc),
            static fn(array $m) => strtolower($m['type']) !== self::MSGTYPE_WARNING
        ));
    }

    /**
     * A one-line summary of what went wrong, for a log row or a flash message.
     */
    public static function summariseErrors(DOMDocument $doc): string
    {
        $errors = self::collectErrors($doc);

        if (!$errors) {
            $messages = self::collectMessages($doc);

            if (!$messages) {
                return 'Twinfield rejected the document without saying why.';
            }

            $errors = $messages;
        }

        $parts = [];

        foreach (array_slice($errors, 0, 3) as $error) {
            $parts[] = "{$error['field']}: {$error['message']}";
        }

        $suffix = count($errors) > 3 ? sprintf(' (+%d more)', count($errors) - 3) : '';

        return implode('; ', $parts) . $suffix;
    }

    /**
     * The first descendant element with this tag name, or null.
     */
    public static function first(DOMNode $context, string $tagName): ?DOMElement
    {
        $doc = $context instanceof DOMDocument ? $context : $context->ownerDocument;

        if ($doc === null) {
            return null;
        }

        foreach ($doc->getElementsByTagName($tagName) as $el) {
            if ($context instanceof DOMDocument || self::contains($context, $el)) {
                return $el;
            }
        }

        return null;
    }

    /**
     * The text of a direct child element, or null when it is absent or empty.
     */
    public static function childText(DOMElement $parent, string $tagName): ?string
    {
        foreach ($parent->childNodes as $child) {
            if ($child instanceof DOMElement && $child->nodeName === $tagName) {
                $value = trim($child->textContent);

                return $value === '' ? null : $value;
            }
        }

        return null;
    }

    /**
     * Direct children with this tag name.
     *
     * @return DOMElement[]
     */
    public static function children(DOMElement $parent, string $tagName): array
    {
        $found = [];

        foreach ($parent->childNodes as $child) {
            if ($child instanceof DOMElement && $child->nodeName === $tagName) {
                $found[] = $child;
            }
        }

        return $found;
    }

    /**
     * Append a child element carrying a text value.
     *
     * A null or empty value is skipped rather than written as an empty tag: Twinfield reads an
     * empty element as "clear this field", which on an update silently wipes data the merchant
     * set by hand in the Twinfield UI.
     *
     * @param array<string, string|null> $attributes
     */
    public static function append(
        DOMElement $parent,
        string $name,
        string|int|float|null $value = null,
        array $attributes = [],
        bool $allowEmpty = false,
    ): ?DOMElement {
        $value = $value === null ? null : (string)$value;

        if (!$allowEmpty && ($value === null || $value === '')) {
            return null;
        }

        $doc = $parent->ownerDocument;

        if ($doc === null) {
            return null;
        }

        // createElement() does not escape its value argument, so build the text node separately.
        $el = $doc->createElement($name);

        if ($value !== null && $value !== '') {
            $el->appendChild($doc->createTextNode($value));
        }

        foreach ($attributes as $attribute => $attributeValue) {
            if ($attributeValue !== null && $attributeValue !== '') {
                $el->setAttribute($attribute, (string)$attributeValue);
            }
        }

        $parent->appendChild($el);

        return $el;
    }

    /**
     * Append a container element with no text of its own.
     *
     * @param array<string, string|null> $attributes
     */
    public static function container(DOMElement $parent, string $name, array $attributes = []): DOMElement
    {
        $doc = $parent->ownerDocument;
        $el = $doc->createElement($name);

        foreach ($attributes as $attribute => $value) {
            if ($value !== null && $value !== '') {
                $el->setAttribute($attribute, (string)$value);
            }
        }

        $parent->appendChild($el);

        return $el;
    }

    /**
     * A fresh document with the given root element.
     *
     * @param array<string, string|null> $attributes
     */
    public static function document(string $rootName, array $attributes = []): DOMDocument
    {
        $doc = new DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = true;

        $root = $doc->createElement($rootName);

        foreach ($attributes as $attribute => $value) {
            if ($value !== null && $value !== '') {
                $root->setAttribute($attribute, (string)$value);
            }
        }

        $doc->appendChild($root);

        return $doc;
    }

    /**
     * Serialise without the XML declaration.
     *
     * The document goes inside a SOAP body, and a nested `<?xml ?>` declaration is not legal
     * there — .NET's XML reader rejects the whole envelope rather than the inner document, which
     * surfaces as an unhelpful SOAP fault about the request being malformed.
     */
    public static function toString(DOMDocument $doc): string
    {
        $xml = trim($doc->saveXML($doc->documentElement) ?: '');

        // `DOMDocument::createTextNode()` escapes `<` and `&` but leaves `>` alone, and a literal
        // `]]>` is illegal in XML content. So a merchant whose invoice footer contains one gets a
        // document that is not well-formed, and Twinfield answers with a fault about the request
        // rather than about the invoice. Nothing here ever emits a CDATA section, so the sequence
        // has no legitimate meaning and can be escaped unconditionally.
        return str_replace(']]>', ']]&gt;', $xml);
    }

    /**
     * Pretty-print a response for the log detail screen.
     */
    public static function pretty(string $xml): string
    {
        try {
            $doc = self::parse($xml);
        } catch (\Throwable) {
            return $xml;
        }

        $doc->formatOutput = true;
        $doc->preserveWhiteSpace = false;

        return $doc->saveXML() ?: $xml;
    }

    private static function hasFailedDescendant(DOMElement $root): bool
    {
        $failed = false;

        self::walk($root, function(DOMElement $el) use (&$failed) {
            if ($el->getAttribute('result') === '0') {
                $failed = true;
            }
        });

        return $failed;
    }

    private static function walk(DOMElement $el, callable $callback): void
    {
        $callback($el);

        foreach ($el->childNodes as $child) {
            if ($child instanceof DOMElement) {
                self::walk($child, $callback);
            }
        }
    }

    private static function contains(DOMNode $ancestor, DOMNode $node): bool
    {
        for ($parent = $node->parentNode; $parent !== null; $parent = $parent->parentNode) {
            if ($parent->isSameNode($ancestor)) {
                return true;
            }
        }

        return false;
    }
}
