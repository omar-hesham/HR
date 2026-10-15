<?php

namespace App\Policies;

use App\Domain\Access\Permission;
use App\Models\Criterion;
use App\Models\User;

/**
 * Criteria follow their version: editable only inside a draft the user may edit.
 */
final class CriterionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permission::JobsView) || $user->hasPermission(Permission::JobsManage);
    }

    public function view(User $user, Criterion $criterion): bool
    {
        return (new CriteriaVersionPolicy)->view($user, $criterion->version);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permission::CriteriaEditDraft);
    }

    public function update(User $user, Criterion $criterion): bool
    {
        return (new CriteriaVersionPolicy)->update($user, $criterion->version);
    }

    public function delete(User $user, Criterion $criterion): bool
    {
        return $this->update($user, $criterion);
    }
}
