<?php

namespace justinholtweb\twinsies\models;

/**
 * The Twinfield coordinates for a single order line, after every fallback has been applied.
 */
class LineMapping
{
    public function __construct(
        public readonly ?string $article = null,
        public readonly ?string $subarticle = null,
        /** Twinfield dim1. */
        public readonly ?string $revenueGl = null,
        public readonly ?string $vatCode = null,
        /** Where this came from, for the "why did it book there?" column in the CP. */
        public readonly string $source = 'default',
    ) {
    }

    public function withVatCode(?string $vatCode): self
    {
        return new self($this->article, $this->subarticle, $this->revenueGl, $vatCode, $this->source);
    }
}
