<?php

namespace App\Services\Applications;

use App\Domain\Access\ReviewAccess;
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
        DB::transaction(function () use ($application, $to, $actor, $reason, $reviewReason): void {
            $locked = Application::query()->whereKey($application->id)->lockForUpdate()->firstOrFail();
            $application->setRawAttributes($locked->getAttributes(), true);
            $from = $application->status;
            if ($from === $to) {
                return;
            }
            // Existing extraction-retry actions explicitly authorize their pipeline operation.
            if ($actor !== null && $from->requiresHuman($to)) {
                abort_unless(ReviewAccess::transition($actor, $application, $to), 403);
            }
            if ($actor !== null && ! $from->requiresHuman($to)) {
                abort_unless($to === ApplicationStatus::Processing && ReviewAccess::manage($actor, $application), 403);
            }
            $this->guard->assertAllowed($from, $to, $actor !== null, $reason);
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
