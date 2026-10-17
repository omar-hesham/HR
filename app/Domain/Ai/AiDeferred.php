<?php

namespace App\Domain\Ai;

use RuntimeException;

final class AiDeferred extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
