<?php

namespace Database\Factories;

use App\Domain\Criteria\CriteriaVersionStatus;
use App\Models\CriteriaVersion;
use App\Models\RecruitmentJob;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CriteriaVersion>
 */
class CriteriaVersionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'recruitment_job_id' => RecruitmentJob::factory(),
            'version' => 1,
            'status' => CriteriaVersionStatus::Draft,
            'thresholds' => ['strong' => 70, 'review' => 40, 'confidence' => 0.80],
        ];
    }
}
