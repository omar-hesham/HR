<?php

namespace Database\Factories;

use App\Models\Application;
use App\Models\CriteriaVersion;
use App\Models\Score;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Score>
 */
class ScoreFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'application_id' => Application::factory(),
            'criteria_version_id' => CriteriaVersion::factory(),
            'score' => 65,
            'band' => 'review',
            'coverage' => 80,
            'rules_version' => '1',
        ];
    }
}
