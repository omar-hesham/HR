<?php

namespace App\Domain\Documents;

use App\Support\HasTranslatedLabel;

/**
 * What happened to one received file (one document_sources row).
 */
enum Disposition: string
{
    use HasTranslatedLabel;

    case Pending = 'pending';
    case Stored = 'stored';
    case DuplicateLinked = 'duplicate_linked';
    case Rejected = 'rejected';
    case Excluded = 'excluded';

    public static function translationGroup(): string
    {
        return 'enums.disposition';
    }
}
