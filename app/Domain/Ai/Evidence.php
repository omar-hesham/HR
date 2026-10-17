<?php

namespace App\Domain\Ai;

final readonly class Evidence
{
    /**
     * @param array<string, mixed> $items */
    public function __construct(public array $items = []) {}
}
