<?php

namespace App\Domain\Documents;

use App\Support\HasTranslatedLabel;

enum DocumentKind: string
{
    use HasTranslatedLabel;

    case Cv = 'cv';
    case SupportingDocument = 'supporting_document';
    case Uncertain = 'uncertain';
    case Other = 'other';

    public static function translationGroup(): string
    {
        return 'enums.document_kind';
    }
}
