<?php

namespace App\Policies;

use App\Domain\Access\JobScope;
use App\Domain\Access\Permission;
use App\Models\Application;
use App\Models\Document;
use App\Models\DocumentSource;
use App\Models\RecruitmentJob;
use App\Models\User;

/**
 * CV file access (§4 "View / download CV file"), enforced on the download route
 * itself, not only by hiding buttons (M9-P0-01).
 */
final class DocumentPolicy
{
    public function upload(User $user, ?RecruitmentJob $job = null): bool
    {
        return $user->hasPermission(Permission::DocumentsUpload)
            && JobScope::canWorkOnJob($user, $job?->id);
    }

    public function download(User $user, Document $document): bool
    {
        if ($user->hasPermission(Permission::DocumentsDownloadAll)) {
            return true;
        }

        if (! $user->hasPermission(Permission::DocumentsDownloadAssigned)) {
            return false;
        }

        $applicationIds = DocumentSource::query()
            ->where('document_id', $document->id)
            ->whereNotNull('application_id')
            ->pluck('application_id');

        return Application::query()
            ->whereIn('id', $applicationIds)
            ->get()
            ->contains(fn (Application $application): bool => JobScope::canViewApplication($user, $application));
    }

    public function view(User $user, Document $document): bool
    {
        return $this->download($user, $document);
    }
}
