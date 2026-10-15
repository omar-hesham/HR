<?php

namespace App\Domain\Access;

use App\Support\HasTranslatedLabel;

/**
 * Roles from spec §4 plus the operator/support role (kickoff §7.3).
 */
enum Role: string
{
    use HasTranslatedLabel;

    case Owner = 'owner';
    case HiringManager = 'hiring_manager';
    case Recruiter = 'recruiter';
    case Coordinator = 'coordinator';
    case Management = 'management';
    case Operator = 'operator';

    public static function translationGroup(): string
    {
        return 'enums.role';
    }

    /**
     * Default grants per role (the §4 matrix without its "Restricted" cells).
     *
     * @return list<Permission>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::Owner => [
                Permission::ApplicationsViewAll,
                Permission::ApplicationsViewUnassigned,
                Permission::DocumentsDownloadAll,
                Permission::DocumentsUpload,
                Permission::ReviewManage,
                Permission::StatusChange,
                Permission::RejectionReopen,
                Permission::ScoresOverride,
                Permission::JobsView,
                Permission::JobsManage,
                Permission::CriteriaEditDraft,
                Permission::CriteriaApprove,
                Permission::ExportsAll,
                Permission::UsersManage,
                Permission::UsersProvision,
                Permission::AccessLogView,
                Permission::SettingsBusiness,
                Permission::OpsHealthView,
                Permission::CandidatesDeleteApprove,
                Permission::RetentionDueView,
            ],
            self::HiringManager => [
                Permission::ApplicationsViewAssigned,
                Permission::DocumentsDownloadAssigned,
                Permission::DocumentsUpload,
                Permission::StatusChange,
                Permission::ScoresOverride,
                Permission::JobsView,
                Permission::CriteriaEditDraft,
                Permission::ExportsAssigned,
                Permission::CandidatesDeleteRequest,
            ],
            self::Recruiter => [
                Permission::ApplicationsViewAssigned,
                Permission::ApplicationsViewUnassigned,
                Permission::DocumentsDownloadAssigned,
                Permission::DocumentsUpload,
                Permission::ReviewManage,
                Permission::StatusChange,
                Permission::JobsView,
                Permission::CandidatesDeleteRequest,
            ],
            self::Coordinator => [
                Permission::UsersProvision,
                Permission::SettingsIntegration,
                Permission::OpsHealthView,
            ],
            self::Management => [
                Permission::ApplicationsViewShortlist,
                Permission::JobsView,
            ],
            self::Operator => [
                Permission::OpsHealthView,
            ],
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $r): string => $r->value, self::cases());
    }
}
