<?php

namespace App\Policies;

use App\Domain\Access\JobScope;
use App\Domain\Access\Permission;
use App\Models\CriteriaVersion;
use App\Models\RecruitmentJob;
use App\Models\User;

/**
 * §4 "Edit / approve criteria": owner yes; hiring manager drafts for assigned jobs,
 * approval only with the explicit criteria.approve grant (D-57).
 */
final class CriteriaVersionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permission::JobsView) || $user->hasPermission(Permission::JobsManage);
    }

    public function view(User $user, CriteriaVersion $version): bool
    {
        return (new RecruitmentJobPolicy)->view($user, $version->job);
    }

    public function createFor(User $user, RecruitmentJob $job): bool
    {
        return $user->hasPermission(Permission::CriteriaEditDraft) && JobScope::canWorkOnJob($user, $job->id);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permission::CriteriaEditDraft);
    }

    public function update(User $user, CriteriaVersion $version): bool
    {
        return $version->isEditable()
            && $user->hasPermission(Permission::CriteriaEditDraft)
            && JobScope::canWorkOnJob($user, $version->recruitment_job_id);
    }

    public function approve(User $user, CriteriaVersion $version): bool
    {
        return $version->isEditable()
            && $user->hasPermission(Permission::CriteriaApprove)
            && JobScope::canWorkOnJob($user, $version->recruitment_job_id);
    }

    public function delete(User $user, CriteriaVersion $version): bool
    {
        return $this->update($user, $version);
    }
}
