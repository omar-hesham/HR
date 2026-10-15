<?php

namespace App\Policies;

use App\Domain\Access\JobScope;
use App\Domain\Access\Permission;
use App\Models\DocumentSource;
use App\Models\User;

/**
 * "Needs review" items. Recruiters and the owner resolve them; hiring managers
 * see items of their assigned jobs read-only.
 */
final class DocumentSourcePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permission::ReviewManage)
            || $user->hasPermission(Permission::ApplicationsViewAssigned);
    }

    public function view(User $user, DocumentSource $source): bool
    {
        if ($source->application === null) {
            // Rejected/unlinked files: intake staff only.
            return $user->hasPermission(Permission::ReviewManage);
        }

        return JobScope::canViewApplication($user, $source->application);
    }

    public function resolve(User $user, DocumentSource $source): bool
    {
        return $user->hasPermission(Permission::ReviewManage) && $this->view($user, $source);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, DocumentSource $source): bool
    {
        return false;
    }

    public function delete(User $user, DocumentSource $source): bool
    {
        return false;
    }
}
