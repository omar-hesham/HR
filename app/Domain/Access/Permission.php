<?php

namespace App\Domain\Access;

/**
 * Permission names (spatie/laravel-permission). The §4 matrix is expressed by
 * Role::permissions(); "Restricted" cells are permissions that no role carries
 * by default and must be granted to a single user explicitly (D-11).
 */
enum Permission: string
{
    case ApplicationsViewAll = 'applications.view_all';
    case ApplicationsViewAssigned = 'applications.view_assigned';
    case ApplicationsViewUnassigned = 'applications.view_unassigned';
    case ApplicationsViewShortlist = 'applications.view_shortlist';

    case DocumentsDownloadAll = 'documents.download_all';
    case DocumentsDownloadAssigned = 'documents.download_assigned';
    case DocumentsUpload = 'documents.upload';
    case ReviewManage = 'review.manage';

    case StatusChange = 'status.change';
    case RejectionReopen = 'status.reopen_rejection';
    case ScoresOverride = 'scores.override';

    case JobsView = 'jobs.view';
    case JobsManage = 'jobs.manage';
    case CriteriaEditDraft = 'criteria.edit_draft';
    case CriteriaApprove = 'criteria.approve';

    case ExportsAll = 'exports.all';
    case ExportsAssigned = 'exports.assigned';

    case UsersManage = 'users.manage';
    case UsersProvision = 'users.provision';

    case AccessLogView = 'access_log.view';
    case AccessLogViewAssigned = 'access_log.view_assigned';

    case SettingsBusiness = 'settings.business';
    case SettingsIntegration = 'settings.integration';
    case OpsHealthView = 'ops.health.view';
    case MailboxesManage = 'mailboxes.manage';

    case CandidatesDeleteApprove = 'candidates.delete_approve';
    case CandidatesDeleteRequest = 'candidates.delete_request';

    case RetentionDueView = 'retention.due_view';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $p): string => $p->value, self::cases());
    }
}
