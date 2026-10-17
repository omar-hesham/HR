<?php

namespace App\Domain\Ai;

final readonly class HealthResult extends ProviderResult
{
    public function healthy(): bool
    {
        return ($this->data['healthy'] ?? false) === true;
    }
}
