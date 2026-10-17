<?php

namespace App\Services\Privacy;

use App\Domain\Privacy\RetentionTrack;
use App\Models\Application;
use App\Models\Candidate;
use App\Services\Settings\SettingsRepository;
use Illuminate\Support\Carbon;

final readonly class RetentionPolicy
{
    public function __construct(private SettingsRepository $settings) {}

    public function assertValidPeriods(): void
    {
        foreach (['retention.applicant_days', 'retention.idle_days', 'retention.hard_max_days', 'retention.owner_notice_days', 'retention.hired_handover_days'] as $key) {
            if ($this->settings->int($key) <= 0) {
                throw new \RuntimeException('retention_policy_invalid');
            }
        }
        if ($this->settings->int('retention.owner_notice_days') >= $this->settings->int('retention.hard_max_days')) {
            throw new \RuntimeException('retention_policy_invalid');
        }
    }

    public function due(Application $application): ?Carbon
    {
        if ($application->retention_track === RetentionTrack::HiredHandover) {
            return $application->handed_over_at?->copy()->addDays($this->settings->int('retention.hired_handover_days'));
        }
        if ($application->final_decision_at !== null) {
            return $application->final_decision_at->copy()->addDays($this->settings->int('retention.applicant_days'));
        }
        if ($application->last_activity_at->copy()->addDays($this->settings->int('retention.idle_days'))->isFuture()) {
            return null;
        }

        return $application->last_activity_at->copy()->addDays($this->settings->int('retention.applicant_days'));
    }

    public function hardDue(Candidate $candidate): Carbon
    {
        $ids = app(IdentityGroup::class)->ids($candidate->id);
        $firstReceipt = Candidate::query()->whereIn('id', $ids)->min('received_at');
        $earliestCap = Candidate::query()->whereIn('id', $ids)->min('retention_hard_due_at');
        $calculated = Carbon::parse($firstReceipt)->addDays($this->settings->int('retention.hard_max_days'));
        if ($earliestCap !== null && Carbon::parse($earliestCap)->lt($calculated)) {
            return Carbon::parse($earliestCap);
        }

        return $candidate->retention_hard_due_at !== null && $candidate->retention_hard_due_at->lt($calculated)
            ? $candidate->retention_hard_due_at->copy() : $calculated;
    }
}
