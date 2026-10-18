<?php

namespace App\Services\Ai;

use App\Domain\Criteria\CriteriaVersionStatus;
use App\Models\CriteriaVersion;
use App\Services\Settings\SettingsRepository;

final readonly class CriteriaSnapshot
{
    public function __construct(private SettingsRepository $settings) {}

    public function saving(CriteriaVersion $version): void
    {
        if ($version->status !== CriteriaVersionStatus::Approved || (! $version->isDirty('status') && $version->exists)) {
            return;
        }
        $this->settings->flush();
        $snapshot = ['strong' => $this->settings->float('scoring.strong'), 'review' => $this->settings->float('scoring.review'), 'confidence' => $this->settings->float('scoring.confidence'), 'education_levels' => $this->settings->get('scoring.education_levels'), 'synonyms' => $this->settings->get('scoring.synonyms'), 'negative_markers' => config('ai-rules.negative_markers'), 'affirmative_statements' => config('ai-rules.affirmative_statements'), 'negative_shift_statements' => config('ai-rules.negative_shift_statements'), 'military_enabled' => $this->settings->bool('ai.military_enabled') && trim($this->settings->string('ai.military_legal_ref')) !== ''];
        $snapshot['normalization'] = config('recruitment.comparison_vocabulary');
        $existing = $version->thresholds ?? [];
        foreach (['salary_currency', 'salary_period'] as $key) {
            if (isset($existing[$key])) {
                $snapshot[$key] = $existing[$key];
            }
        }
        $version->thresholds = $snapshot;
    }
}
