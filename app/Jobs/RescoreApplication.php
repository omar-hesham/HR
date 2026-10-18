<?php

namespace App\Jobs;

use Illuminate\Support\Str;

final class RescoreApplication extends ScoreApplication
{
    public function __construct(int $applicationId, ?int $versionId = null)
    {
        // Stable across queue retries; a distinct explicit request appends a new evaluation.
        parent::__construct($applicationId, $versionId, (string) Str::uuid(), false);
    }
}
