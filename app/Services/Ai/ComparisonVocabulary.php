<?php

namespace App\Services\Ai;

use App\Domain\Criteria\CriterionField;
use App\Models\RecruitmentJob;
use App\Services\Settings\SettingsRepository;

final readonly class ComparisonVocabulary
{
    public function __construct(private SettingsRepository $settings) {}

    /** @param array<string,mixed> $snapshot
     * @return array<string,mixed> */
    public function forEvaluation(array $snapshot): array
    {
        // Legacy approved versions keep their thresholds/dictionary; v1 lacked comparison aliases.
        return $snapshot + ['normalization' => config('recruitment.comparison_vocabulary')];
    }

    /** @return array<string,mixed> */
    public function forJob(RecruitmentJob $job): array
    {
        $version = $job->currentCriteriaVersion;
        $snapshot = $this->forEvaluation($version->thresholds ?? []);
        $criteria = [];
        foreach ($version->criteria ?? [] as $criterion) {
            $field = $criterion->fieldEnum();
            if ($field === null || $field->isHumanAnswered() || $field === CriterionField::LegalMinimumAgeConfirmed) {
                continue;
            }
            $criteria[] = ['field' => $field->value, 'operator' => $criterion->operator->value, 'target' => $criterion->target_json];
        }

        return ['qualification_levels' => $snapshot['education_levels'] ?? config('recruitment.qualification_levels'),
            'shift_availability' => ['available', 'not_available'], 'aliases' => $snapshot['normalization'],
            'synonyms' => $snapshot['synonyms'] ?? $this->settings->get('scoring.synonyms'), 'criteria' => $criteria];
    }
}
