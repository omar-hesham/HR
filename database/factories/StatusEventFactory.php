<?php

namespace Database\Factories;

use App\Domain\Applications\ApplicationStatus;
use App\Models\Application;
use App\Models\StatusEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StatusEvent>
 */
class StatusEventFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'application_id' => Application::factory(),
            'from_status' => null,
            'to_status' => ApplicationStatus::Received,
            'created_at' => now(),
        ];
    }
}
