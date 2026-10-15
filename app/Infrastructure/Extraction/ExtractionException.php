<?php

namespace App\Infrastructure\Extraction;

use App\Domain\Documents\ReviewReason;
use RuntimeException;
use Throwable;

/**
 * Extraction failure with a review reason. Transient failures are retried by the
 * queue; permanent ones go straight to "Needs review". Messages never contain CV text.
 */
final class ExtractionException extends RuntimeException
{
    public function __construct(
        public readonly ReviewReason $reason,
        public readonly bool $transient,
        string $message = '',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message !== '' ? $message : $reason->value, 0, $previous);
    }

    public static function permanent(ReviewReason $reason, string $message = '', ?Throwable $previous = null): self
    {
        return new self($reason, false, $message, $previous);
    }

    public static function transient(string $message, ?Throwable $previous = null): self
    {
        return new self(ReviewReason::ExtractionFailed, true, $message, $previous);
    }
}
