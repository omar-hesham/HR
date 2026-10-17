<?php

namespace App\Domain\Intake;

/**
 * Job identified (or not) from message keywords (M1-P0-02).
 */
final class JobHint
{
    public function __construct(
        public readonly ?int $jobId,
        public readonly bool $ambiguous,
    ) {}
}
