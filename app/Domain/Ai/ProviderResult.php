<?php

namespace App\Domain\Ai;

abstract readonly class ProviderResult
{
    /**
     * @param array<string, mixed> $data */
    public function __construct(
        public array $data,
        public string $requestId,
        public string $modelId,
        public UsageSummary $usage = new UsageSummary,
        public int $durationMs = 0,
    ) {}
}
