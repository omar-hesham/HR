<?php

namespace App\Domain\Access;

use App\Domain\Applications\ApplicationStatus;
use App\Models\Application;
use App\Models\User;

final class ReviewAccess
{
    public static function manage(User $user, Application $application): bool
    {
        return $user->isUsable() && $user->hasPermission(Permission::ReviewManage)
            && JobScope::canViewApplication($user, $application) && $application->status !== ApplicationStatus::Deleted;
    }

    public static function transition(User $user, Application $application, ApplicationStatus $to): bool
    {
        if (! $user->isUsable() || ! $user->hasPermission(Permission::StatusChange)
            || ! JobScope::canViewApplication($user, $application)
            || ! in_array($to, $application->status->allowedNext(), true)) {
            return false;
        }
        // Deletion and pipeline statuses are never generic UI decisions.
        if (in_array($to, [ApplicationStatus::Deleted, ApplicationStatus::Received, ApplicationStatus::Processing, ApplicationStatus::Scored], true)) {
            return false;
        }
        if ($application->status === ApplicationStatus::RejectedByHuman && $to === ApplicationStatus::NeedsReview) {
            return $user->hasRole(Role::Owner->value) && $user->hasPermission(Permission::RejectionReopen);
        }
        if (in_array($to, [ApplicationStatus::RejectedByHuman, ApplicationStatus::Hired, ApplicationStatus::NotHired, ApplicationStatus::Archived], true)) {
            return $user->hasRole([Role::Owner->value, Role::HiringManager->value]);
        }

        return $user->hasRole([Role::Owner->value, Role::HiringManager->value, Role::Recruiter->value]);
    }

    /** @return array<string,string> */
    public static function statusOptions(User $user, Application $application): array
    {
        $options = [];
        foreach ($application->status->allowedNext() as $status) {
            if (self::transition($user, $application, $status)) {
                $options[$status->value] = $status->label();
            }
        }

        return $options;
    }
}
