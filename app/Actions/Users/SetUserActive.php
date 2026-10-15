<?php

namespace App\Actions\Users;

use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * (De)activation by the owner or the coordinator (provisioning). Deactivation ends sessions at once (M9-P0-02).
 */
final class SetUserActive
{
    public function handle(User $target, bool $active, User $actor): void
    {
        Gate::forUser($actor)->authorize('update', $target);

        if ($target->active === $active) {
            return;
        }

        $target->forceFill(['active' => $active])->save();

        activity('access')
            ->causedBy($actor)
            ->performedOn($target)
            ->event($active ? 'user.activated' : 'user.deactivated')
            ->log($active ? 'user.activated' : 'user.deactivated');

        if (! $active) {
            SyncUserAccess::endSessions($target);
        }
    }
}
