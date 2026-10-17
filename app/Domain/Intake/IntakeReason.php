<?php

namespace App\Domain\Intake;

use App\Support\HasTranslatedLabel;

/**
 * Message-level reasons (M1-P0-03: every outcome has a reason). Item-level reasons
 * live on document_sources (ReviewReason codes or classifier codes).
 */
enum IntakeReason: string
{
    use HasTranslatedLabel;

    case CvProcessed = 'cv_processed';
    case DuplicateMessage = 'duplicate_message';
    case ParseFailed = 'parse_failed';
    case NoAttachments = 'no_attachments';
    case NoCvAttachment = 'no_cv_attachment';

    public static function translationGroup(): string
    {
        return 'enums.intake_reason';
    }
}
