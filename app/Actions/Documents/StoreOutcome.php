<?php

namespace App\Actions\Documents;

use App\Domain\Documents\Disposition;
use App\Domain\Documents\ReviewReason;

/**
 * Outcome of storing one received file.
 */
final class StoreOutcome
{
    public function __construct(
        public readonly int $sourceId,
        public readonly Disposition $disposition,
        public readonly ?ReviewReason $reviewReason = null,
        public readonly ?string $reason = null,
        public readonly ?int $documentId = null,
        public readonly ?int $applicationId = null,
        public readonly bool $createdApplication = false,
    ) {}
}
