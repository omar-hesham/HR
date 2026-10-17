<?php

namespace App\Domain\Ai;

final readonly class JobContext
{
    public function __construct(public string $jobId, public string $title, public bool $militaryEnabled = false) {}
}
