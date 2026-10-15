<?php

namespace Database\Factories;

use App\Models\RecruitmentJob;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Synthetic, illustrative jobs only (spec §7.5: not the factory's criteria).
 *
 * @extends Factory<RecruitmentJob>
 */
class RecruitmentJobFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $n = fake()->unique()->numberBetween(1, 99999);

        return [
            'title_ar' => 'وظيفة تجريبية '.$n,
            'title_en' => 'Demo job '.$n,
            'aliases' => ['demo-'.$n],
            'active' => true,
            'name_blind_review' => false,
        ];
    }
}
