<?php

namespace Database\Factories;

use App\Models\Mailbox;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Mailbox>
 */
class MailboxFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'provider' => 'imap',
            'address' => 'jobs@example.test',
            'host' => 'imap.example.test',
            'port' => 993,
            'encryption' => 'ssl',
            'auth_type' => 'password',
            'credential_ref' => 'MAILBOX',
            'silence_alert_hours' => 48,
            'active' => true,
        ];
    }
}
