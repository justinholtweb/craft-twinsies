<?php

namespace justinholtweb\twinsies\errors;

/**
 * Something Twinfield said no to, or could not be asked.
 *
 * Deliberately a plain exception rather than something clever: the interesting information is the
 * `msg` attributes Twinfield puts on the tags it disliked, and those are already flattened into
 * the message by `helpers\Xml::summariseErrors()`.
 */
class TwinfieldException extends \RuntimeException
{
    /**
     * Whether trying again could plausibly work — a timeout or a 503, as opposed to an invoice
     * Twinfield will reject just as firmly next time.
     */
    public bool $retryable = false;

    /**
     * Whether Twinfield may have accepted the document anyway — the connection failed after it
     * was sent. Such a document is parked rather than retried, since a resend could post it twice.
     */
    public bool $unconfirmed = false;

    /**
     * The field-level messages, innermost first, when the failure came from a document.
     *
     * @var array<int, array{type: string, field: string, message: string}>
     */
    public array $messages = [];

    /**
     * @param array<int, array{type: string, field: string, message: string}> $messages
     */
    public static function make(string $message, bool $retryable = false, array $messages = [], ?\Throwable $previous = null): self
    {
        $exception = new self($message, 0, $previous);
        $exception->retryable = $retryable;
        $exception->messages = $messages;

        return $exception;
    }
}
