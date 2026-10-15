<?php

namespace App\Domain\Criteria;

use App\Support\HasTranslatedLabel;

enum CriterionType: string
{
    use HasTranslatedLabel;

    case MustHave = 'must_have';
    case NiceToHave = 'nice_to_have';
    case Disqualifier = 'disqualifier';

    public static function translationGroup(): string
    {
        return 'enums.criterion_type';
    }

    public function isWeighted(): bool
    {
        return $this === self::NiceToHave;
    }
}
