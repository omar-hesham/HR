<?php

namespace App\Domain\Documents;

use App\Support\HasTranslatedLabel;

/**
 * Why an item is in the "Needs review" list. Codes are stable; labels are translated.
 */
enum ReviewReason: string
{
    use HasTranslatedLabel;

    case InvalidType = 'invalid_type';
    case FileTooLarge = 'file_too_large';
    case SignatureMismatch = 'signature_mismatch';
    case EmptyFile = 'empty_file';
    case PasswordProtected = 'password_protected';
    case ImageOnly = 'image_only';
    case CorruptFile = 'corrupt_file';
    case DocConversionDisabled = 'doc_conversion_disabled';
    case ExtractionFailed = 'extraction_failed';
    case LowQuality = 'low_quality';
    case SuspectedReversed = 'suspected_reversed';
    case DuplicateOfUnreadable = 'duplicate_of_unreadable';

    // Milestone 2 (mail intake).
    case MalwareDetected = 'malware_detected';
    case ImageAttachment = 'image_attachment';
    case UncertainAttachment = 'uncertain_attachment';

    public static function translationGroup(): string
    {
        return 'enums.review_reason';
    }
}
