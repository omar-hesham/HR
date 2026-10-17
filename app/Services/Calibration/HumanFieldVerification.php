<?php

namespace App\Services\Calibration;

use App\Domain\Ai\EvidenceVerifier;
use App\Domain\Ai\Schema;
use App\Jobs\ScoreApplication;
use App\Models\Application;
use App\Models\Extraction;
use App\Models\FieldCorrection;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class HumanFieldVerification
{
    /** @param array<string,mixed> $fields */
    public function enter(User $actor, Application $application, array $fields, string $reason): void
    {
        app(Calibration::class)->authorize($actor, (int) $application->recruitment_job_id);
        abort_unless($actor->can('view', $application) && $application->job?->getAttribute('rules_only') && trim($reason) !== '', 403);
        (new Schema)->validate($fields);
        $source = $application->candidate->activeDocument?->latestExtraction;
        abort_unless($source !== null && $source->normalizedText() !== null, 422);
        $fields = (new EvidenceVerifier)->verify($fields, $source->normalizedText(), $source->document_id);
        $mark = function (array $branch) use (&$mark): array {
            if (array_key_exists('value', $branch) && array_key_exists('evidence', $branch)) {
                $branch['source'] = 'human_correction';
            }
            foreach ($branch as $key => $part) {
                if (is_array($part)) {
                    $branch[$key] = $mark($part);
                }
            }

            return $branch;
        };
        $extraction = $source->replicate();
        $extraction->forceFill(['fields_json_enc' => json_encode($mark($fields), JSON_THROW_ON_ERROR), 'schema_version' => Schema::VERSION, 'status' => Extraction::STATUS_OK])->save();
        $this->verify($actor, $application->fresh(), $reason);
    }

    public function verify(User $actor, Application $application, string $reason): void
    {
        app(Calibration::class)->authorize($actor, (int) $application->recruitment_job_id);
        abort_unless($actor->can('view', $application) && trim($reason) !== '', 403);
        $extraction = $application->candidate->activeDocument?->latestExtraction;
        abort_unless($extraction !== null && $extraction->schema_version === Schema::VERSION && $extraction->fields_json_enc !== null, 422);
        DB::table('human_field_verifications')->insert(['application_id' => $application->id, 'extraction_id' => $extraction->id, 'revision_hash' => $this->revision($application, $extraction), 'user_id' => $actor->id, 'reason_enc' => encrypt($reason), 'created_at' => now()]);
        ScoreApplication::dispatch($application->id)->afterCommit();
    }

    public function valid(Application $application, Extraction $extraction): bool
    {
        return DB::table('human_field_verifications')->where('application_id', $application->id)->where('extraction_id', $extraction->id)->where('revision_hash', $this->revision($application, $extraction))->exists();
    }

    private function revision(Application $application, Extraction $extraction): string
    {
        return hash('sha256', json_encode([$extraction->fields_json_enc, FieldCorrection::query()->where('application_id', $application->id)->where('extraction_id', $extraction->id)->orderBy('id')->pluck('id')->all()], JSON_THROW_ON_ERROR));
    }
}
