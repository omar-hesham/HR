<?php

namespace App\Policies;

use App\Domain\Access\JobScope;
use App\Domain\Access\Permission;
use App\Models\Application;
use App\Models\User;

final class ApplicationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permission::ApplicationsViewAll)
            || $user->hasPermission(Permission::ApplicationsViewAssigned)
            || $user->hasPermission(Permission::ApplicationsViewUnassigned)
            || $user->hasPermission(Permission::ApplicationsViewShortlist);
    }

    public function view(User $user, Application $application): bool
    {
        return JobScope::canViewApplication($user, $application);
    }

    // Applications are created by intake/upload actions only, and never deleted from the UI.
    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Application $application): bool
    {
        return false;
    }

    public function delete(User $user, Application $application): bool
    {
        return false;
    }
}
