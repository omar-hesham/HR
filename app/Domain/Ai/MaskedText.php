<?php

namespace App\Domain\Ai;

readonly class MaskedText
{
    /**
     * @param array<string, string> $substitutions */
    public function __construct(public string $text, public DispatchContext $context = new DispatchContext, public array $substitutions = [], public bool $succeeded = false) {}
}
