<?php

namespace App\Domain\Documents;

use App\Support\HasTranslatedLabel;

enum DocumentStatus: string
{
    use HasTranslatedLabel;

    case Pending = 'pending';
    case Extracting = 'extracting';
    case Extracted = 'extracted';
    case NeedsReview = 'needs_review';

    public static function translationGroup(): string
    {
        return 'enums.document_status';
    }
}
