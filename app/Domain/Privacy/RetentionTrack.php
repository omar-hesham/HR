<?php

namespace App\Domain\Privacy;

use App\Support\HasTranslatedLabel;

/**
 * Kickoff §5.5: applicants follow the in-tool retention policy; hired candidates
 * are handed over to the employee file (5-year rule, Labour Law 14/2025) outside this tool.
 */
enum RetentionTrack: string
{
    use HasTranslatedLabel;

    case Applicant = 'applicant';
    case HiredHandover = 'hired_handover';

    public static function translationGroup(): string
    {
        return 'enums.retention_track';
    }
}
