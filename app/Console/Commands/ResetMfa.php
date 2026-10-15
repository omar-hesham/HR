<?php

namespace App\Console\Commands;

use App\Actions\Users\ResetUserMfa;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * M9-P0-04 MFA recovery: run by the operator after an out-of-band identity check.
 * The user must enrol again at next login; the reset is written to the access log.
 */
final class ResetMfa extends Command
{
    protected $signature = 'recruit:mfa-reset {email} {--reason= : Why and how identity was verified (required)}';

    protected $description = 'Reset a user\'s MFA after verifying their identity (logged)';

    public function handle(ResetUserMfa $action): int
    {
        $reason = trim((string) $this->option('reason'));

        if ($reason === '') {
            $this->error('--reason is required.');

            return self::FAILURE;
        }

        $user = User::query()->where('email', (string) $this->argument('email'))->first();

        if ($user === null) {
            $this->error('User not found.');

            return self::FAILURE;
        }

        $action->handle($user, $reason);
        $this->info('MFA reset; sessions ended. The user must enrol again at next login.');

        return self::SUCCESS;
    }
}
