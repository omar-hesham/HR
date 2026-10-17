<?php

namespace App\Services\Ai;

use App\Domain\Ai\AiError;
use App\Domain\Ai\AiException;
use App\Domain\Ai\AiProvider;

final class AiDriverFactory
{
    public function driver(?string $requestId = null): AiProvider
    {
        return match (config('ai-pipeline.driver')) {
            'fake' => app()->environment('local', 'testing') ? app(FakeAiDriver::class) : throw new AiException(AiError::ProviderUnavailable),
            'local','cloud' => new LaravelAiDriver((string) config('ai-pipeline.driver'), $requestId),
            default => throw new AiException(AiError::ProviderUnavailable),
        };
    }
}
