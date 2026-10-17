<?php

namespace App\Services\Ai;

use App\Services\Settings\SettingsRepository;
use Laravel\Ai\AnonymousAgent;

final class ExtractionAgent extends AnonymousAgent
{
    public function temperature(): float
    {
        return 0.0;
    }

    public function maxTokens(): int
    {
        return app(SettingsRepository::class)->int('ai.max_output_tokens');
    }

    public function maxSteps(): int
    {
        return 1;
    }
}
