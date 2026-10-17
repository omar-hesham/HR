<?php

namespace App\Jobs;

use App\Actions\Privacy\DeleteCandidateData;
use App\Domain\Privacy\DeletionReason;
use App\Domain\Privacy\RetentionTrack;
use App\Models\Candidate;
use App\Services\Operations\OperationsAlerts;
use App\Services\Privacy\ErasureFiles;
use App\Services\Privacy\RetentionPolicy;
use App\Services\Settings\SettingsRepository;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use Throwable;

final class RetentionSweep implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 600;

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300];
    }

    /** @return list<WithoutOverlapping> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('retention-sweep'))->expireAfter(900)];
    }

    public function handle(RetentionPolicy $policy, DeleteCandidateData $delete, SettingsRepository $settings): void
    {
        app(ErasureFiles::class)->purge();
        $policy->assertValidPeriods();
        foreach (Candidate::query()->whereNull('erased_at')->whereNull('merged_into_id')->lazyById(100) as $candidate) {
            $due = $policy->hardDue($candidate);
            $candidate->forceFill(['retention_hard_due_at' => $due])->save();
            if ($due->copy()->subDays($settings->int('retention.owner_notice_days'))->lte(now()) && $candidate->retention_notice_at === null) {
                DB::transaction(function () use ($candidate, $due): void {
                    DB::table('retention_notices')->insertOrIgnore(['candidate_ref' => $candidate->uuid, 'hard_due_at' => $due, 'created_at' => now()]);
                    $candidate->forceFill(['retention_notice_at' => now()])->save();
                    app(OperationsAlerts::class)->record('retention.hard_cap_notice');
                });
            }
            if ($due->lte(now())) {
                $delete->candidate($candidate->id, DeletionReason::HardCap);

                continue;
            }
            foreach ($candidate->applications()->where('status', '!=', 'deleted')->get() as $application) {
                $deadline = $policy->due($application);
                $application->forceFill(['retention_due_at' => $deadline])->save();
                if ($deadline === null || $deadline->isFuture()) {
                    continue;
                }
                $handover = $application->retention_track === RetentionTrack::HiredHandover && $application->handed_over_at !== null;
                if (! $application->status->isActive() || $handover) {
                    $delete->application($application->id, $handover ? DeletionReason::HiredHandover : DeletionReason::Retention);
                }
            }
        }
    }

    public function failed(?Throwable $exception): void
    {
        app(OperationsAlerts::class)->record('retention.failed');
    }
}
