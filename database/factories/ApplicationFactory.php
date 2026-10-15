<?php

namespace Database\Factories;

use App\Domain\Applications\ApplicationStatus;
use App\Domain\Documents\SourceChannel;
use App\Domain\Privacy\RetentionTrack;
use App\Models\Application;
use App\Models\Candidate;
use App\Models\RecruitmentJob;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Application>
 */
class ApplicationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'candidate_id' => Candidate::factory(),
            'recruitment_job_id' => RecruitmentJob::factory(),
            'source' => SourceChannel::Upload,
            'status' => ApplicationStatus::Received,
            'received_at' => now(),
            'last_activity_at' => now(),
            'retention_track' => RetentionTrack::Applicant,
        ];
    }

    public function unassigned(): static
    {
        return $this->state(fn (): array => ['recruitment_job_id' => null]);
    }

    public function status(ApplicationStatus $status): static
    {
        return $this->state(fn (): array => ['status' => $status]);
    }
}
