<?php

namespace App\Policies;

use App\Domain\Access\Permission;
use App\Models\User;

/**
 * §4 "User management": owner yes; coordinator provisions accounts only (D-14):
 * it can create and (de)activate accounts but never assign roles, permissions or job scopes.
 */
final class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permission::UsersManage) || $user->hasPermission(Permission::UsersProvision);
    }

    public function view(User $user, User $target): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, User $target): bool
    {
        if ($user->hasPermission(Permission::UsersManage)) {
            return true;
        }

        // A provisioner may not edit accounts that can manage users (no lock-out or takeover).
        return $user->hasPermission(Permission::UsersProvision)
            && ! $target->hasPermission(Permission::UsersManage);
    }

    public function assignRoles(User $user, User $target): bool
    {
        return $user->hasPermission(Permission::UsersManage) && $user->id !== $target->id;
    }

    public function delete(User $user, User $target): bool
    {
        return false;
    }
}
