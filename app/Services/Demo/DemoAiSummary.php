<?php

namespace App\Services\Demo;

use App\Domain\Ai\AiError;
use App\Domain\Documents\ReviewReason;
use App\Models\AiRun;
use App\Models\AiWorkItem;
use App\Models\Application;
use App\Models\Document;
use App\Models\Extraction;
use App\Models\Score;

final class DemoAiSummary
{
    /** @return array<string,mixed> */
    public function snapshot(): array
    {
        $codes = [...array_column(AiError::cases(), 'value'), ...array_column(ReviewReason::cases(), 'value'),
            'awaiting_service', 'awaiting_budget', 'human_verification_required', 'quote_not_found', 'quote_ambiguous',
            'value_not_supported', 'reservation_exceeded', 'usage_unavailable'];
        $failures = [];
        foreach ([AiRun::query()->whereNotNull('error_code')->pluck('error_code'),
            Extraction::query()->whereNotNull('error_code')->pluck('error_code'),
            AiWorkItem::query()->whereNotNull('reason')->whereNot('state', 'done')->pluck('reason')] as $errors) {
            foreach ($errors as $error) {
                $safe = in_array($error, $codes, true) ? $error : 'ProviderUnavailable';
                $failures[$safe] = ($failures[$safe] ?? 0) + 1;
            }
        }

        $bands = [];
        foreach (['strong_match', 'needs_review', 'weak_match', 'configuration_review'] as $band) {
            $bands[$band] = Application::query()->whereHas('currentScore', fn ($query) => $query->where('band', $band))->count();
        }

        return [...$bands, 'documents' => Document::query()->count(), 'ai_runs' => AiRun::query()->count(),
            'failure_records' => array_sum($failures),
            'pending_documents' => Document::query()->whereIn('status', ['pending', 'extracting'])->count(),
            'succeeded' => AiRun::query()->where('status', 'succeeded')->count(), 'scores' => Score::query()->count(),
            'pending' => AiWorkItem::query()->whereIn('state', ['waiting', 'queued'])->count(), 'failures' => $failures];
    }
}
