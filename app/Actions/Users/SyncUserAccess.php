<?php

namespace App\Actions\Users;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Role, direct-permission and job-scope changes (M9-P0-03): logs actor + old/new values
 * and ends the user's sessions whenever access is reduced, so revocation is immediate.
 */
final class SyncUserAccess
{
    /**
     * @param  list<string>  $roles
     * @param  list<string>  $permissions  explicit "Restricted" grants (D-11)
     * @param  list<int>  $jobIds
     */
    public function handle(User $target, array $roles, array $permissions, array $jobIds, User $actor): void
    {
        Gate::forUser($actor)->authorize('assignRoles', $target);

        DB::transaction(function () use ($target, $roles, $permissions, $jobIds, $actor): void {
            $old = $this->snapshot($target);

            $target->syncRoles($roles);
            $target->syncPermissions($permissions);
            $target->assignedJobs()->sync($jobIds);
            $target->unsetRelation('roles')->unsetRelation('permissions');

            $new = $this->snapshot($target->fresh() ?? $target);

            if ($old === $new) {
                return;
            }

            $reduced = array_diff($old['roles'], $new['roles']) !== []
                || array_diff($old['permissions'], $new['permissions']) !== []
                || array_diff($old['jobs'], $new['jobs']) !== [];

            activity('access')
                ->causedBy($actor)
                ->performedOn($target)
                ->event('user.access_changed')
                ->withProperties(['old' => $old, 'new' => $new, 'sessions_revoked' => $reduced])
                ->log('user.access_changed');

            if ($reduced) {
                self::endSessions($target);
            }
        });
    }

    public static function endSessions(User $user): void
    {
        DB::table('sessions')->where('user_id', $user->id)->delete();
        $user->forceFill(['remember_token' => null])->saveQuietly();
    }

    /**
     * @return array{roles: list<string>, permissions: list<string>, jobs: list<int>}
     */
    private function snapshot(User $user): array
    {
        $roles = $user->getRoleNames()->sort()->values()->all();
        $permissions = $user->getDirectPermissions()->pluck('name')->sort()->values()->all();
        $jobs = $user->assignedJobIds();
        sort($jobs);

        /** @var list<string> $roles */
        /** @var list<string> $permissions */
        return ['roles' => $roles, 'permissions' => $permissions, 'jobs' => $jobs];
    }
}
