<?php

namespace App\Policies;

use App\Domain\Access\Permission;
use App\Models\User;
use Spatie\Activitylog\Models\Activity;

/**
 * §4 "View access log": owner yes; hiring manager only with the explicit
 * access_log.view_assigned grant (scoped in the resource query); nobody edits it.
 */
final class ActivityPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permission::AccessLogView) || $user->hasPermission(Permission::AccessLogViewAssigned);
    }

    public function view(User $user, Activity $activity): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Activity $activity): bool
    {
        return false;
    }

    public function delete(User $user, Activity $activity): bool
    {
        return false;
    }
}
