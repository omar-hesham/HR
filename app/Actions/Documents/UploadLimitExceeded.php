<?php

namespace App\Actions\Documents;

use RuntimeException;

final class UploadLimitExceeded extends RuntimeException
{
    public function __construct(public readonly string $translationKey, public readonly int $limit)
    {
        parent::__construct($translationKey);
    }
}
