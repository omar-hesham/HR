<?php

namespace App\Services\Calibration;

use App\Domain\Access\JobScope;
use App\Domain\Calibration\AgreementMetrics;
use App\Models\Application;
use App\Models\RecruitmentJob;
use App\Models\User;
use App\Services\Review\ReviewApplications;
use App\Services\Settings\SettingsRepository;
use Illuminate\Support\Facades\DB;

final class Calibration
{
    public function authorize(User $actor, int $jobId): void
    {
        abort_unless($actor->isUsable() && $actor->can('calibration.manage') && JobScope::canWorkOnJob($actor, $jobId), 403);
    }

    /** @param list<int> $applicationIds */
    public function start(User $actor, RecruitmentJob $job, array $applicationIds, string $sampleType = 'synthetic'): int
    {
        $this->authorize($actor, $job->id);
        abort_unless(count($applicationIds) >= 2 && count($applicationIds) <= 100 && count(array_unique($applicationIds)) === count($applicationIds), 422);
        abort_unless(in_array($sampleType, ['synthetic', 'egypt_pilot'], true), 422);
        abort_unless($sampleType === 'synthetic' || (app()->environment('production') && trim(app(SettingsRepository::class)->string('operations.residency_approval_ref')) !== ''), 403);
        $version = $job->currentCriteriaVersion;
        abort_unless($version !== null && $version->approved_at !== null, 422);

        return DB::transaction(function () use ($actor, $job, $applicationIds, $version, $sampleType): int {
            $id = DB::table('calibration_batches')->insertGetId(['recruitment_job_id' => $job->id, 'criteria_version_id' => $version->id, 'manager_id' => $actor->id, 'synthetic' => $sampleType === 'synthetic', 'created_at' => now(), 'updated_at' => now()]);
            foreach ($applicationIds as $applicationId) {
                $application = Application::query()->findOrFail($applicationId);
                abort_unless($application->recruitment_job_id === $job->id && $actor->can('view', $application), 403);
                DB::table('calibration_entries')->insert(['calibration_batch_id' => $id, 'application_id' => $applicationId]);
            }

            return $id;
        });
    }

    /** @return list<array{application_id:int,entered_at:?string,document_uuid:?string}> */
    public function blindEntries(User $actor, int $batchId): array
    {
        $batch = DB::table('calibration_batches')->where('id', $batchId)->first();
        abort_unless($batch !== null, 404);
        $this->authorize($actor, $batch->recruitment_job_id);
        $rows = [];
        foreach (DB::table('calibration_entries')->where('calibration_batch_id', $batchId)->get() as $entry) {
            $application = Application::query()->find($entry->application_id);
            abort_unless($application !== null && $actor->can('view', $application), 403);
            $document = $application->candidate->activeDocument;
            $rows[] = ['application_id' => $application->id, 'entered_at' => $entry->entered_at, 'document_uuid' => $document !== null && $actor->can('download', $document) ? $document->uuid : null];
        }

        return $rows;
    }

    public function input(User $actor, int $batchId, int $applicationId, float $rank, bool $suitable): void
    {
        DB::transaction(function () use ($actor, $batchId, $applicationId, $rank, $suitable): void {
            $batch = DB::table('calibration_batches')->where('id', $batchId)->lockForUpdate()->first();
            abort_unless($batch !== null, 404);
            $this->authorize($actor, $batch->recruitment_job_id);
            abort_unless($batch->manager_id === $actor->id, 403);
            abort_unless($batch->locked_at === null && $rank > 0 && $rank <= 100, 422);
            $entry = DB::table('calibration_entries')->where('calibration_batch_id', $batchId)->where('application_id', $applicationId)->first();
            abort_unless($entry !== null && $entry->entered_at === null, 422);
            DB::table('calibration_entries')->where('id', $entry->id)->update(['manager_rank' => $rank, 'suitable' => $suitable, 'entered_at' => now()]);
            if (! DB::table('calibration_entries')->where('calibration_batch_id', $batchId)->whereNull('entered_at')->exists()) {
                $this->lock($batch);
            }
        });
    }

    private function lock(object $batch): void
    {
        $entries = DB::table('calibration_entries')->where('calibration_batch_id', $batch->id)->get();
        $ranked = [];
        foreach ($entries as $entry) {
            $application = Application::query()->with('currentScore')->find($entry->application_id);
            $score = $application?->currentScore;
            $current = $application !== null && $application->status->value !== 'deleted' && $score !== null && $score->criteria_version_id === $batch->criteria_version_id && app(ReviewApplications::class)->isCurrent($application);
            DB::table('calibration_entries')->where('id', $entry->id)->update(['presented' => $application !== null && $application->status->value !== 'deleted']);
            if ($current) {
                $ranked[] = ['id' => $entry->id, 'band' => array_search($score->band, ['strong_match', 'needs_review', 'weak_match'], true), 'score' => (float) $score->score, 'coverage' => (float) $score->coverage];
            }
        }
        usort($ranked, fn (array $a, array $b): int => $a['band'] <=> $b['band'] ?: $b['score'] <=> $a['score'] ?: $b['coverage'] <=> $a['coverage'] ?: $a['id'] <=> $b['id']);
        foreach ($ranked as $index => $row) {
            $ties = array_keys(array_filter($ranked, fn (array $other): bool => $other['band'] === $row['band'] && $other['score'] === $row['score'] && $other['coverage'] === $row['coverage']));
            $rank = array_sum(array_map(fn (int $i): int => $i + 1, $ties)) / count($ties);
            DB::table('calibration_entries')->where('id', $row['id'])->update(['tool_rank' => $rank]);
        }
        DB::table('calibration_batches')->where('id', $batch->id)->update(['locked_at' => now()]);
    }

    /** @return array<string,mixed> */
    public function results(User $actor, int $batchId): array
    {
        $batch = DB::table('calibration_batches')->where('id', $batchId)->first();
        abort_unless($batch !== null, 404);
        $this->authorize($actor, $batch->recruitment_job_id);
        abort_unless($batch->locked_at !== null, 403, __('ops.blind_locked'));
        $entries = DB::table('calibration_entries')->where('calibration_batch_id', $batchId)->get()->map(fn ($e): array => ['application_id' => (int) $e->application_id, 'manager_rank' => (float) $e->manager_rank, 'tool_rank' => $e->tool_rank === null ? null : (float) $e->tool_rank, 'suitable' => (bool) $e->suitable, 'presented' => (bool) $e->presented])->all();

        return (new AgreementMetrics)->calculate($entries);
    }

    public function checkpoint(User $actor, int $batchId, string $outcome, string $reason): void
    {
        abort_unless($actor->isUsable() && $actor->can('candidates.delete_approve'), 403);
        $metrics = $this->results($actor, $batchId);
        abort_unless(in_array($outcome, ['approved', 'rules_only'], true) && trim($reason) !== '', 422);
        DB::transaction(function () use ($actor, $batchId, $outcome, $reason, $metrics): void {
            $batch = DB::table('calibration_batches')->where('id', $batchId)->lockForUpdate()->first();
            abort_if(DB::table('calibration_checkpoints')->where('calibration_batch_id', $batchId)->exists(), 422);
            DB::table('calibration_checkpoints')->insert(['calibration_batch_id' => $batchId, 'approved_by' => $actor->id, 'outcome' => $outcome, 'metrics' => json_encode($metrics, JSON_THROW_ON_ERROR), 'reason_enc' => encrypt($reason), 'created_at' => now()]);
            if ($outcome === 'rules_only') {
                DB::table('recruitment_jobs')->where('id', $batch->recruitment_job_id)->update(['rules_only' => true]);
                DB::table('applications')->where('recruitment_job_id', $batch->recruitment_job_id)->update(['current_score_id' => null]);
                DB::table('ai_work_items')->whereIn('application_id', Application::query()->where('recruitment_job_id', $batch->recruitment_job_id)->select('id'))->where('state', '!=', 'done')->update(['state' => 'review', 'reason' => 'human_verification_required']);
            }
            activity('calibration')->causedBy($actor)->event('calibration.week3_decision')->withProperties(['batch_id' => $batchId, 'outcome' => $outcome])->log('calibration.week3_decision');
        });
    }
}
