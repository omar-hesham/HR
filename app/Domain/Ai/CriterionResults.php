<?php

namespace App\Domain\Ai;

final readonly class CriterionResults
{
    /**
     * @param array<string, string> $claims */
    public function __construct(public array $claims, public DispatchContext $context) {}
}
