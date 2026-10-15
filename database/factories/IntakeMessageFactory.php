<?php

namespace Database\Factories;

use App\Domain\Documents\SourceChannel;
use App\Models\IntakeMessage;
use App\Models\Mailbox;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<IntakeMessage>
 */
class IntakeMessageFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'mailbox_id' => Mailbox::factory(),
            'source' => SourceChannel::Email,
            'external_id' => '1:'.Str::random(12),
            'raw_sha256' => hash('sha256', Str::random(32)),
            'received_at' => now(),
            'outcome' => 'pending',
        ];
    }
}
