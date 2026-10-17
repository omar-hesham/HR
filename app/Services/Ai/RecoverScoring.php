<?php

namespace App\Services\Ai;

use App\Jobs\RescoreApplication;
use App\Models\Application;

/** Covers a lost enqueue and a correction arriving while a unique score job is finishing. */
final class RecoverScoring
{
    public function dispatch(): void
    {
        Application::query()->select('applications.*')
            ->join('recruitment_jobs as current_job', 'current_job.id', '=', 'applications.recruitment_job_id')
            ->leftJoin('scores as latest_score', 'latest_score.id', '=', 'applications.current_score_id')
            ->leftJoin('evaluation_keys as evaluation_key', 'evaluation_key.score_id', '=', 'latest_score.id')
            ->where('applications.status', '!=', 'deleted')->whereNotNull('current_job.current_criteria_version_id')
            ->where(function ($query): void {
                $query->whereNull('latest_score.id')->orWhereColumn('latest_score.criteria_version_id', '!=', 'current_job.current_criteria_version_id')
                    ->orWhereExists(function ($corrections): void {
                        $corrections->selectRaw('1')->from('field_corrections')->whereColumn('field_corrections.application_id', 'applications.id')->whereRaw('field_corrections.id > COALESCE(evaluation_key.correction_revision, 0)');
                    });
            })->chunkById(100, function ($applications): void {
                foreach ($applications as $application) {
                    $document = $application->candidate->activeDocument;
                    if ($document !== null && $document->extractions()->where('status', 'ok')->where('schema_version', '1.0')->whereNotNull('fields_json_enc')->exists()) {
                        RescoreApplication::dispatch($application->id)->afterCommit();
                    }
                }
            }, 'applications.id', 'id');
    }
}
