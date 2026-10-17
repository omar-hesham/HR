<?php

namespace App\Actions\Scoring;

use App\Domain\Access\Permission;
use App\Domain\Ai\EvidenceVerifier;
use App\Jobs\ScoreApplication;
use App\Models\Application;
use App\Models\Extraction;
use App\Models\FieldCorrection;
use App\Models\User;
use App\Services\Settings\SettingsRepository;
use Illuminate\Support\Facades\DB;

final class CorrectField
{
    /**
     * @param array<string,mixed> $fact */
    public function handle(Application $application, Extraction $extraction, string $path, array $fact, User $actor, string $reason, string $source = 'human_correction'): FieldCorrection
    {
        abort_unless($actor->can('view', $application) && $actor->hasPermission(Permission::ReviewManage), 403);
        abort_if(trim($reason) === '' || ! in_array($source, ['human_correction', 'human_answer'], true), 422);
        abort_unless($extraction->document_id === $application->candidate->active_document_id && $extraction->fields_json_enc !== null, 422);
        // Corrections cannot introduce protected fields or location, even through nested paths.
        $allowed = '/^(?:education\.\d+\.(?:level|field)|experience_total_years|experience_relevant_years\.\d+\.years|previous_roles\.\d+\.(?:title|employer|start_date|end_date)|(?:skills|certifications|languages)\.\d+|shift_availability|can_reach_plant_for_shift|legal_minimum_age_confirmed|military_status|expected_salary\.(?:amount|currency|period))$/D';
        abort_unless(preg_match($allowed, $path) === 1, 422);
        abort_if(! array_key_exists('value', $fact) || ! isset($fact['confidence'],$fact['evidence']) || ! is_numeric($fact['confidence']) || $fact['confidence'] < 0 || $fact['confidence'] > 1, 422);
        $humanAnswer = in_array($path, ['can_reach_plant_for_shift', 'legal_minimum_age_confirmed'], true);
        if ($path === 'military_status' || $path === 'legal_minimum_age_confirmed') {
            $settings = app(SettingsRepository::class);
            abort_unless($path === 'military_status' ? $settings->bool('ai.military_enabled') && trim($settings->string('ai.military_legal_ref')) !== '' : trim($settings->string('ai.minimum_age_legal_ref')) !== '', 422);
        }
        if ($humanAnswer && (! is_bool($fact['value']) || empty($fact['evidence']))) {
            abort(422);
        }
        if (! $humanAnswer) {
            $fact = (new EvidenceVerifier)->verify(['fact' => $fact], $extraction->normalizedText() ?? '', $extraction->document_id)['fact'];
        }
        $fact['source'] = $source;
        $correction = DB::transaction(function () use ($application, $extraction, $path, $fact, $actor, $reason, $source): FieldCorrection {
            Application::query()->whereKey($application->id)->lockForUpdate()->firstOrFail();
            $fields = json_decode($extraction->fields_json_enc ?? '{}', true, 512, JSON_THROW_ON_ERROR);
            foreach (FieldCorrection::query()->where('application_id', $application->id)->where('extraction_id', $extraction->id)->orderBy('id')->get() as $previous) {
                data_set($fields, $previous->path, json_decode($previous->after_enc, true, 512, JSON_THROW_ON_ERROR));
            }
            $row = FieldCorrection::query()->create(['application_id' => $application->id, 'extraction_id' => $extraction->id, 'path' => $path, 'before_enc' => json_encode(data_get($fields, $path), JSON_THROW_ON_ERROR), 'after_enc' => json_encode($fact, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'user_id' => $actor->id, 'source' => $source, 'reason_enc' => $reason]);
            activity('review')->causedBy($actor)->performedOn($application)->event('field.corrected')->withProperties(['correction_id' => $row->id, 'path' => $path])->log('field.corrected');

            return $row;
        });
        ScoreApplication::dispatch($application->id)->afterCommit();

        return $correction;
    }
}
