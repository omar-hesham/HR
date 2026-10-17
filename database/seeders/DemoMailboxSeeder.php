<?php

namespace Database\Seeders;

use App\Models\Mailbox;
use Illuminate\Database\Seeder;

/**
 * Local/testing only: one fake-provider mailbox pointing at the configured
 * .eml folder (D-101). No credentials, no real server.
 */
class DemoMailboxSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            return;
        }

        Mailbox::query()->firstOrCreate(['address' => 'recruitment@example.test'], [
            'provider' => 'fake',
            'auth_type' => 'password',
            'credential_ref' => 'default',
            'host' => (string) config('recruitment.intake.fake_path'),
            'active' => true,
            'start_at' => now()->subDays(30),
        ]);
    }
}
