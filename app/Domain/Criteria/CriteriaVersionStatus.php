<?php

namespace App\Domain\Criteria;

use App\Support\HasTranslatedLabel;

enum CriteriaVersionStatus: string
{
    use HasTranslatedLabel;

    case Draft = 'draft';
    case Approved = 'approved';
    case Superseded = 'superseded';

    public static function translationGroup(): string
    {
        return 'enums.criteria_version_status';
    }

    public function isEditable(): bool
    {
        return $this === self::Draft;
    }
}
