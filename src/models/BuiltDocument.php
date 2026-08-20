<?php

namespace justinholtweb\twinsies\models;

/**
 * The XML for one document, plus what building it decided.
 *
 * Returned by `services\Documents` and passed straight to `services\Sync`, so a preview shown in
 * the control panel is the same bytes the push sends — not a re-render that might differ.
 */
class BuiltDocument
{
    public function __construct(
        public readonly string $xml,
        public readonly string $kind,
        public readonly string $mode,
        public readonly string $office,
        public readonly string $bookCode,
        public readonly ?string $customerCode,
        public readonly string $currency,
        public readonly float $total,
        /** @var array<int, string> problems that did not stop the build but a merchant should see */
        public readonly array $warnings = [],
    ) {
    }

    public function hash(): string
    {
        return sha1($this->xml);
    }
}
