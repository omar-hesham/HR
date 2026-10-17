<?php

namespace App\Domain\Ai;

use RuntimeException;

final class AiException extends RuntimeException
{
    public function __construct(public readonly AiError $error)
    {
        parent::__construct($error->value);
    }
}
