<?php

namespace App\Services\Ai;

use App\Domain\Ai\AiDeferred;
use App\Domain\Ai\AiError;
use App\Domain\Ai\AiException;
use App\Domain\Ai\DispatchContext;
use App\Models\Application;
use App\Services\Settings\SettingsRepository;

final readonly class DispatchGuard
{
    public function __construct(private SettingsRepository $settings, private ConsentRepository $consents) {}

    public function check(DispatchContext $context, string $purpose, bool $masked = true): void
    {
        if ($context->applicationId !== null && Application::query()->find($context->applicationId)?->job?->getAttribute('rules_only')) {
            throw new AiDeferred('human_verification_required');
        }
        $this->settings->flush();
        if (! $this->settings->bool('ai.enabled')) {
            throw new AiDeferred('awaiting_service');
        }
        if (! in_array(config('ai-pipeline.driver'), ['fake', 'local', 'cloud'], true)) {
            throw new AiDeferred('awaiting_service');
        }
        if ($purpose !== 'health' && ! $masked) {
            throw new AiException(AiError::MaskingFailed);
        }
        if (config('ai-pipeline.driver') === 'cloud' && trim($this->settings->string('ai.legal_approval_ref')) === '') {
            throw new AiDeferred('awaiting_service');
        }
        if ($purpose !== 'health' && (config('ai-pipeline.driver') === 'cloud' || $purpose === 'classify') && ! $this->consents->verified($context)) {
            throw new AiDeferred('awaiting_service');
        }
    }
}
