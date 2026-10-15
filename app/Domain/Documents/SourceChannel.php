<?php

namespace App\Domain\Documents;

use App\Support\HasTranslatedLabel;

/**
 * Channel an application or file arrived through (kickoff §5.1).
 * Only Email and Upload are implemented in Phase 1; the schema accepts all.
 */
enum SourceChannel: string
{
    use HasTranslatedLabel;

    case Email = 'email';
    case Upload = 'upload';
    case Whatsapp = 'whatsapp';
    case WebForm = 'web_form';
    case WalkIn = 'walk_in';
    case Platform = 'platform';

    public static function translationGroup(): string
    {
        return 'enums.source';
    }
}
