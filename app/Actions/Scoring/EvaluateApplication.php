<?php

namespace App\Actions\Scoring;

use App\Domain\Ai\Schema;
use App\Domain\Applications\ApplicationStatus;
use App\Domain\Criteria\CriteriaVersionStatus;
use App\Domain\Documents\DocumentKind;
use App\Domain\Scoring\ExplanationTemplate;
use App\Domain\Scoring\RulesEngine;
use App\Infrastructure\Storage\EncryptedDocumentStore;
use App\Models\AiExtractionContext;
use App\Models\AiRun;
use App\Models\Application;
use App\Models\CriteriaVersion;
use App\Models\Criterion;
use App\Models\Extraction;
use App\Models\FieldCorrection;
use App\Models\Score;
use App\Services\Ai\AiWorkCoordinator;
use App\Services\Applications\ApplicationStatusService;
use Illuminate\Support\Facades\DB;

final readonly class EvaluateApplication
{
    public function __construct(private RulesEngine $engine, private ExplanationTemplate $template, private ApplicationStatusService $statuses) {}

    public function handle(int $applicationId, ?int $versionId = null): ?Score
    {
        $score = DB::transaction(function () use ($applicationId, $versionId): ?Score {
            $app = Application::query()->whereKey($applicationId)->lockForUpdate()->first();
            if ($app === null || $app->status === ApplicationStatus::Deleted || $app->job === null) {
                return null;
            }
            $document = $app->candidate->activeDocument;
            if ($document === null || $document->kind !== DocumentKind::Cv || $document->path === null || ! app(EncryptedDocumentStore::class)->exists($document->path)) {
                return null;
            }
            $extraction = $document->extractions()->where('schema_version', Schema::VERSION)->where('status', Extraction::STATUS_OK)->whereNotNull('fields_json_enc')->latest('id')->first();
            if ($extraction === null) {
                return null;
            }
            $version = CriteriaVersion::query()->whereKey($versionId ?? $app->job->current_criteria_version_id)->where('recruitment_job_id', $app->recruitment_job_id)->first();
            if ($version === null) {
                return null;
            }
            $fields = json_decode($extraction->fields_json_enc ?? '{}', true, 512, JSON_THROW_ON_ERROR);
            $corrections = FieldCorrection::query()->where('application_id', $app->id)->where('extraction_id', $extraction->id)->orderBy('id')->get();
            foreach ($corrections as $correction) {
                data_set($fields, $correction->path, json_decode($correction->after_enc, true, 512, JSON_THROW_ON_ERROR));
            }
            $key = hash('sha256', json_encode([$app->id, $version->id, $extraction->id, RulesEngine::VERSION, $fields, $version->thresholds, $corrections->pluck('id')->all()], JSON_THROW_ON_ERROR));
            $existing = DB::table('evaluation_keys')->where('idempotency_key', $key)->value('score_id');
            if ($existing !== null) {
                return Score::query()->find($existing);
            }
            $criteria = $version->criteria()->get();
            $approved = $version->approved_at !== null && in_array($version->status, [CriteriaVersionStatus::Approved, CriteriaVersionStatus::Superseded], true);
            $out = $this->engine->evaluate($criteria->map(fn (Criterion $c) => $c->toData())->all(), $fields, (string) $app->recruitment_job_id, $approved, $version->thresholds ?? []);
            $context = AiExtractionContext::query()->where('extraction_id', $extraction->id)->first();
            $run = AiRun::query()->find($context?->ai_run_id);
            $score = Score::query()->create(['application_id' => $app->id, 'criteria_version_id' => $version->id, 'extraction_id' => $extraction->id, 'score' => $out['score'], 'coverage' => $out['coverage'], 'band' => $out['band'], 'rules_version' => RulesEngine::VERSION, 'schema_version' => $extraction->schema_version, 'prompt_version' => $run->prompt_version ?? 'extract-v1', 'model_id' => $run->model ?? 'human-verified', 'driver' => $run->driver ?? 'fake']);
            foreach ($criteria as $index => $criterion) {
                $result = $out['results'][$index];
                $score->criterionResults()->create(['criterion_id' => $criterion->id, 'result' => $result['result'], 'evidence_enc' => json_encode($result['evidence'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'confidence' => $result['confidence'], 'source' => $result['source']]);
            }
            $score->forceFill(['reason_enc' => $this->template->render($score)])->save();
            DB::table('evaluation_keys')->insert(['idempotency_key' => $key, 'score_id' => $score->id, 'correction_revision' => (int) ($corrections->max('id') ?? 0), 'created_at' => now(), 'updated_at' => now()]);
            if ($version->id === $app->job->current_criteria_version_id) {
                $app->forceFill(['current_score_id' => $score->id])->save();
                // No band/disqualifier changes workflow decisions. Preserve every human status.
                if ($out['score'] !== null && in_array($app->status, [ApplicationStatus::Received, ApplicationStatus::Processing], true)) {
                    if ($app->status === ApplicationStatus::Received) {
                        $this->statuses->transition($app, ApplicationStatus::Processing);
                    }
                    $this->statuses->transition($app, ApplicationStatus::Scored);
                }
            }

            return $score;
        });
        if ($score !== null) {
            app(AiWorkCoordinator::class)->explanation($score->id);
        }

        return $score;
    }
}
