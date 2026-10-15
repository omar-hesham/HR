<?php

namespace App\Services\Applications;

use App\Domain\Applications\ApplicationStatus;
use App\Domain\Applications\StatusTransitionGuard;
use App\Domain\Documents\ReviewReason;
use App\Models\Application;
use App\Models\StatusEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The only writer of applications.status. Every change is checked by
 * StatusTransitionGuard and appended to status_events (WF-P0-02).
 */
final class ApplicationStatusService
{
    public function __construct(private readonly StatusTransitionGuard $guard) {}

    public function transition(
        Application $application,
        ApplicationStatus $to,
        ?User $actor = null,
        ?string $reason = null,
        ?ReviewReason $reviewReason = null,
    ): void {
        $from = $application->status;

        if ($from === $to) {
            return;
        }

        $this->guard->assertAllowed($from, $to, $actor !== null, $reason);

        DB::transaction(function () use ($application, $from, $to, $actor, $reason, $reviewReason): void {
            $application->status = $to;
            $application->review_reason = $to === ApplicationStatus::NeedsReview ? $reviewReason : null;

            if ($actor !== null) {
                $application->last_activity_at = now();
            }

            if ($to->isFinalDecision() && $application->final_decision_at === null) {
                $application->final_decision_at = now();
            }

            $application->save();

            StatusEvent::query()->create([
                'application_id' => $application->id,
                'from_status' => $from,
                'to_status' => $to,
                'user_id' => $actor?->id,
                'reason_code' => $reviewReason?->value,
                'reason_enc' => $reason,
                'created_at' => now(),
            ]);
        });
    }

    /**
     * Initial event for a newly created application.
     */
    public function recordCreated(Application $application, ?User $actor): void
    {
        StatusEvent::query()->create([
            'application_id' => $application->id,
            'from_status' => null,
            'to_status' => $application->status,
            'user_id' => $actor?->id,
            'created_at' => now(),
        ]);
    }
}
