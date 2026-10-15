<?php

namespace App\Domain\Criteria;

use App\Support\HasTranslatedLabel;

/**
 * Allowed operators (SC-P0-02). Target JSON shape per operator:
 *  equals: {value}, in / contains_any / contains_all: {values: [...]},
 *  gte / lte: {value: number}, between: {min, max}, is_explicitly_true: {}.
 */
enum CriterionOperator: string
{
    use HasTranslatedLabel;

    case Equals = 'equals';
    case In = 'in';
    case ContainsAny = 'contains_any';
    case ContainsAll = 'contains_all';
    case Gte = 'gte';
    case Lte = 'lte';
    case Between = 'between';
    case IsExplicitlyTrue = 'is_explicitly_true';

    public static function translationGroup(): string
    {
        return 'enums.criterion_operator';
    }
}
