<?php

namespace App\Domain\Ai;

final readonly class UsageSummary
{
    public function __construct(public int $inputTokens = 0, public int $outputTokens = 0) {}
}
