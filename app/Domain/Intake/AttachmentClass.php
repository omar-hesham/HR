<?php

namespace App\Domain\Intake;

use App\Support\HasTranslatedLabel;

/**
 * Classification of one e-mail attachment before any storage decision (M1-P0-05).
 */
enum AttachmentClass: string
{
    use HasTranslatedLabel;

    case Cv = 'cv';
    case Image = 'image';
    case Uncertain = 'uncertain';
    case Other = 'other';

    public static function translationGroup(): string
    {
        return 'enums.attachment_class';
    }
}
