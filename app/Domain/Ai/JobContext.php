<?php

namespace App\Domain\Ai;

final readonly class JobContext
{
    /** @param array<string,mixed> $vocabulary */
    public function __construct(public string $jobId, public string $title, public bool $militaryEnabled = false, public array $vocabulary = []) {}

    /** @return array<string,mixed> */
    public function trustedData(): array
    {
        return ['job_id' => $this->jobId, 'title' => $this->title, 'military_enabled' => $this->militaryEnabled, 'controlled_vocabulary' => $this->vocabulary];
    }
}
