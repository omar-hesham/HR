<?php

namespace App\Services\Ai;

use App\Services\Settings\SettingsRepository;
use Laravel\Ai\AnonymousAgent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Enums\Lab;

final class ExtractionAgent extends AnonymousAgent implements HasProviderOptions
{
    public const PROMPT_VERSION = 'extract-v2';

    /** @return array<string,mixed> */
    public function providerOptions(Lab|string $provider): array
    {
        // Full schema validation remains local (Opis); Gemini supports JSON object mode.
        return $provider === 'recruit_cloud' ? ['response_format' => ['type' => 'json_object']] : [];
    }

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
