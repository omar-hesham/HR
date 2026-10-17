<?php

namespace App\Services\Intake;

use Illuminate\Support\Carbon;

/**
 * Result of ingesting one raw message.
 */
final class ProcessedMessageResult
{
    public function __construct(
        public readonly bool $ingested,
        public readonly ?int $intakeMessageId,
        public readonly ?string $reason,
        public readonly ?Carbon $receivedAt = null,
        public readonly bool $needsReview = false,
        public readonly bool $cvReceived = false,
        public readonly bool $applicationCreated = false,
    ) {}

    public static function skipped(string $reason): self
    {
        return new self(false, null, $reason);
    }
}
