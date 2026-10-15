<?php

namespace App\Domain\Access;

use App\Domain\Applications\ApplicationStatus;
use App\Models\Application;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Which applications a user may see (§4 matrix "View applicants", D-12/D-13).
 * Used both by policies (single record) and by list queries (no leaks through lists).
 */
final class JobScope
{
    /** Statuses read-only management may see ("approved shortlist"). */
    public const SHORTLIST_STATUSES = [
        ApplicationStatus::Shortlisted,
        ApplicationStatus::Interview,
        ApplicationStatus::Hired,
        ApplicationStatus::NotHired,
    ];

    public static function canViewApplication(User $user, Application $application): bool
    {
        if ($user->hasPermission(Permission::ApplicationsViewAll)) {
            return true;
        }

        $jobId = $application->recruitment_job_id;

        if ($jobId === null) {
            return $user->hasPermission(Permission::ApplicationsViewUnassigned);
        }

        if (! $user->isAssignedToJob($jobId)) {
            return false;
        }

        if ($user->hasPermission(Permission::ApplicationsViewAssigned)) {
            return true;
        }

        return $user->hasPermission(Permission::ApplicationsViewShortlist)
            && in_array($application->status, self::SHORTLIST_STATUSES, true);
    }

    /**
     * @param  Builder<Application>  $query
     * @return Builder<Application>
     */
    public static function applyToApplications(Builder $query, User $user): Builder
    {
        if ($user->hasPermission(Permission::ApplicationsViewAll)) {
            return $query;
        }

        $jobIds = $user->assignedJobIds();
        $assigned = $user->hasPermission(Permission::ApplicationsViewAssigned);
        $shortlist = $user->hasPermission(Permission::ApplicationsViewShortlist);
        $unassigned = $user->hasPermission(Permission::ApplicationsViewUnassigned);

        return $query->where(function (Builder $q) use ($jobIds, $assigned, $shortlist, $unassigned): void {
            $q->whereRaw('1 = 0');

            if ($assigned && $jobIds !== []) {
                $q->orWhereIn('recruitment_job_id', $jobIds);
            }

            if ($shortlist && $jobIds !== []) {
                $q->orWhere(function (Builder $s) use ($jobIds): void {
                    $s->whereIn('recruitment_job_id', $jobIds)
                        ->whereIn('status', array_map(static fn (ApplicationStatus $st): string => $st->value, self::SHORTLIST_STATUSES));
                });
            }

            if ($unassigned) {
                $q->orWhereNull('recruitment_job_id');
            }
        });
    }

    public static function canWorkOnJob(User $user, ?int $jobId): bool
    {
        if ($user->hasPermission(Permission::ApplicationsViewAll)) {
            return true;
        }

        if ($jobId === null) {
            return $user->hasPermission(Permission::ApplicationsViewUnassigned);
        }

        return $user->isAssignedToJob($jobId);
    }
}
