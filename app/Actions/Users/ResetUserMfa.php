<?php

namespace App\Actions\Users;

use App\Models\User;
use InvalidArgumentException;

/**
 * MFA recovery through a logged administrative procedure (M9-P0-04, D-17).
 * Run only after verifying the person's identity out of band.
 */
final class ResetUserMfa
{
    public function handle(User $target, string $reason, ?User $actor = null): void
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('A reason is required.');
        }

        $target->forceFill([
            'app_authentication_secret' => null,
            'app_authentication_recovery_codes' => null,
        ])->save();

        SyncUserAccess::endSessions($target);

        activity('access')
            ->causedBy($actor)
            ->performedOn($target)
            ->event('user.mfa_reset')
            ->withProperties(['reason' => $reason, 'via' => $actor === null ? 'console' : 'panel'])
            ->log('user.mfa_reset');
    }
}
