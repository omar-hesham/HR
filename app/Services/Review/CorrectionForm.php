<?php

namespace App\Services\Review;

use App\Actions\Scoring\CorrectField;
use App\Models\Application;
use App\Models\Extraction;
use App\Models\FieldCorrection;
use App\Models\User;

final class CorrectionForm
{
    /** @return array<string,string> */
    public function paths(Extraction $extraction): array
    {
        $fields = json_decode($extraction->fields_json_enc ?? '{}', true, 512, JSON_THROW_ON_ERROR);
        $paths = ['experience_total_years', 'shift_availability', 'can_reach_plant_for_shift', 'legal_minimum_age_confirmed', 'military_status', 'expected_salary.amount', 'expected_salary.currency', 'expected_salary.period'];
        foreach (['education' => ['level', 'field'], 'experience_relevant_years' => ['years'], 'previous_roles' => ['title', 'employer', 'start_date', 'end_date'], 'skills' => [''], 'certifications' => [''], 'languages' => ['']] as $group => $parts) {
            for ($index = 0; $index <= min(100, count($fields[$group] ?? [])); $index++) {
                foreach ($parts as $part) {
                    $paths[] = $group.'.'.$index.($part === '' ? '' : '.'.$part);
                }
            }
        }

        return array_combine($paths, $paths);
    }

    public function handle(Application $application, User $actor, string $path, string $value, string $evidence, string $reason): FieldCorrection
    {
        $extraction = $application->candidate->activeDocument?->latestExtraction;
        abort_unless($extraction instanceof Extraction && array_key_exists($path, $this->paths($extraction)), 422);
        $decoded = json_decode($value, true);
        $value = json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
        abort_if($value === null || is_array($value) || trim($evidence) === '', 422);
        $humanAnswer = in_array($path, ['can_reach_plant_for_shift', 'legal_minimum_age_confirmed'], true);
        abort_unless($humanAnswer || mb_strpos($extraction->normalizedText() ?? '', $evidence) !== false, 422);
        if (str_starts_with($path, 'experience_relevant_years.')) {
            $fields = json_decode($extraction->fields_json_enc ?? '{}', true, 512, JSON_THROW_ON_ERROR);
            abort_unless((string) data_get($fields, str_replace('.years', '.job_id', $path)) === (string) $application->recruitment_job_id, 422);
        }

        return app(CorrectField::class)->handle($application, $extraction, $path,
            ['value' => $value, 'confidence' => 1, 'evidence' => [['quote' => $evidence, 'document_id' => $extraction->document_id]]],
            $actor, $reason, $humanAnswer ? 'human_answer' : 'human_correction');
    }
}
