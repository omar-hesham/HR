<?php

namespace App\Services\Ai;

use App\Domain\Ai\AiDeferred;
use App\Domain\Ai\AiException;
use App\Domain\Ai\DispatchContext;
use App\Domain\Ai\Schema;
use App\Domain\Applications\ApplicationStatus;
use App\Domain\Scoring\RulesEngine;
use App\Jobs\ClassifyAmbiguousIntake;
use App\Jobs\ExplainScore;
use App\Jobs\ExtractStructuredFields;
use App\Jobs\ScoreApplication;
use App\Models\AiWorkItem;
use App\Models\Application;
use App\Models\Document;
use App\Models\Extraction;
use App\Models\FieldCorrection;
use App\Models\Score;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final readonly class AiWorkCoordinator
{
    public function __construct(private DispatchGuard $guard, private CircuitBreaker $breaker) {}

    public function forApplication(int $id): void
    {
        $application = Application::query()->find($id);
        if ($application === null || $application->recruitment_job_id === null || $application->status === ApplicationStatus::Deleted) {
            return;
        }
        $document = $application->candidate->activeDocument;
        if ($document === null || $document->path === null) {
            return;
        }
        $extraction = $document->extractions()->where('status', Extraction::STATUS_OK)->latest('id')->first();
        if ($extraction === null) {
            return;
        }
        if ($extraction->fields_json_enc !== null && $extraction->schema_version === Schema::VERSION) {
            if ($this->hasCurrentScore($application, $extraction)) {
                return;
            }
            ScoreApplication::dispatch($application->id)->afterCommit();

            return;
        }
        $work = AiWorkItem::query()->firstOrCreate(['idempotency_key' => hash('sha256', 'extract:'.$document->id.':'.$extraction->id.':'.Schema::VERSION)], ['purpose' => 'extract', 'document_id' => $document->id, 'application_id' => $id, 'state' => 'waiting', 'reason' => 'awaiting_service']);
        $this->dispatch($work);
    }

    public function forDocument(int $id): void
    {
        $document = Document::query()->find($id);
        if ($document === null) {
            return;
        }
        foreach ($document->sources()->whereNotNull('application_id')->pluck('application_id') as $applicationId) {
            $this->forApplication((int) $applicationId);
        }
    }

    public function ambiguous(int $intakeId): void
    {
        $work = AiWorkItem::query()->firstOrCreate(['idempotency_key' => hash('sha256', 'classify:'.$intakeId)], ['purpose' => 'classify', 'intake_message_id' => $intakeId, 'state' => 'waiting', 'reason' => 'awaiting_service']);
        $this->dispatch($work);
    }

    public function explanation(int $scoreId): void
    {
        $score = Score::query()->find($scoreId);
        if ($score === null) {
            return;
        }
        $work = AiWorkItem::query()->firstOrCreate(['idempotency_key' => hash('sha256', 'explain:'.$scoreId)], ['purpose' => 'explain', 'score_id' => $scoreId, 'application_id' => $score->application_id, 'document_id' => $score->extraction?->document_id, 'state' => 'waiting', 'reason' => 'awaiting_service']);
        $this->dispatch($work);
    }

    public function dispatch(AiWorkItem $work): void
    {
        Cache::lock('ai-dispatch:'.$work->id, 10)->get(function () use ($work): void {
            $work->refresh();
            if ($work->attempts >= 3 && $work->state !== 'done') {
                $work->forceFill(['state' => 'review', 'reason' => $work->reason ?? 'ProviderUnavailable'])->save();

                return;
            }
            if (in_array($work->state, ['done', 'review', 'failed', 'queued'], true)) {
                return;
            }
            if ($work->available_at !== null && $work->available_at->isFuture()) {
                return;
            }
            $application = Application::query()->find($work->application_id);
            $context = new DispatchContext($work->document_id, $work->application_id, $work->intake_message_id, $work->score_id, $application?->candidate_id);
            try {
                $this->guard->check($context, $work->purpose);
                if (! $this->breaker->canConsume((string) config('ai-pipeline.driver'))) {
                    throw new AiDeferred('awaiting_service');
                }
            } catch (AiDeferred $e) {
                $work->forceFill(['state' => 'waiting', 'reason' => $e->reason])->save();

                return;
            } catch (AiException $e) {
                $work->forceFill(['state' => 'review', 'reason' => $e->error->value])->save();

                return;
            }
            $work->forceFill(['state' => 'queued', 'reason' => null, 'queued_at' => now()])->save();
            $job = match ($work->purpose) {
                'extract' => new ExtractStructuredFields($work->id),'explain' => new ExplainScore($work->id),default => new ClassifyAmbiguousIntake($work->id)
            };
            dispatch($job)->afterCommit();
        });
    }

    public function recover(): void
    {
        // Re-dispatch after lost enqueue or a killed worker; durable attempts cap actual calls.
        AiWorkItem::query()->where('state', 'queued')->where('queued_at', '<', now()->subSeconds((int) config('ai-pipeline.timeout') + 120))->update(['state' => 'waiting', 'reason' => 'awaiting_service']);
        AiWorkItem::query()->where('state', 'waiting')->chunkById(100, function ($batch): void {
            foreach ($batch as $work) {
                $this->dispatch($work);
            }
        });
    }

    public function labelForApplication(int $applicationId): ?string
    {
        $application = Application::query()->find($applicationId);
        if ($application !== null && $application->recruitment_job_id !== null && ($application->job?->current_criteria_version_id === null || Score::query()->whereKey($application->current_score_id)->where('band', 'configuration_review')->exists())) {
            return __('ai.states.configuration_review');
        }
        $work = AiWorkItem::query()->where('application_id', $applicationId)->whereNotIn('state', ['done'])->orderByRaw("CASE state WHEN 'review' THEN 0 ELSE 1 END")->latest('id')->first();

        return $work === null ? null : __('ai.states.'.($work->reason ?? $work->state));
    }

    private function hasCurrentScore(Application $application, Extraction $extraction): bool
    {
        $score = Score::query()->whereKey($application->current_score_id)->where('criteria_version_id', $application->job?->current_criteria_version_id)->where('extraction_id', $extraction->id)->where('rules_version', RulesEngine::VERSION)->whereNotNull('score')->first();
        if ($score === null || $score->criterionResults()->count() !== $score->criteriaVersion->criteria()->count()) {
            return false;
        }
        $revision = (int) (FieldCorrection::query()->where('application_id', $application->id)->where('extraction_id', $extraction->id)->max('id') ?? 0);
        $evaluated = DB::table('evaluation_keys')->where('score_id', $score->id)->value('correction_revision');

        return $evaluated !== null && (int) $evaluated === $revision;
    }
}
