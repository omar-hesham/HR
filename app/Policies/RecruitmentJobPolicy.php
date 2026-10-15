<?php

namespace App\Policies;

use App\Domain\Access\Permission;
use App\Models\RecruitmentJob;
use App\Models\User;

final class RecruitmentJobPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permission::JobsView) || $user->hasPermission(Permission::JobsManage);
    }

    public function view(User $user, RecruitmentJob $job): bool
    {
        if ($user->hasPermission(Permission::JobsManage) || $user->hasPermission(Permission::ApplicationsViewAll)) {
            return true;
        }

        return $user->hasPermission(Permission::JobsView) && $user->isAssignedToJob($job->id);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permission::JobsManage);
    }

    public function update(User $user, RecruitmentJob $job): bool
    {
        return $user->hasPermission(Permission::JobsManage);
    }

    // Jobs are deactivated, never deleted (history keeps references).
    public function delete(User $user, RecruitmentJob $job): bool
    {
        return false;
    }
}
