<?php

namespace App\Policies;

use App\Domain\Access\Permission;
use App\Models\Mailbox;
use App\Models\User;

final class MailboxPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isUsable() && $user->hasAnyRole(['owner', 'operator']) && $user->hasPermission(Permission::MailboxesManage);
    }

    public function view(User $user, Mailbox $mailbox): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, Mailbox $mailbox): bool
    {
        return $this->viewAny($user);
    }

    public function delete(User $user, Mailbox $mailbox): bool
    {
        return false;
    }
}
