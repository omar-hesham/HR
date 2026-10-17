<?php

namespace App\Domain\Intake;

use App\Support\HasTranslatedLabel;

/**
 * Outcome of one intake message (M1-P0-03): determined by the outcomes of its items.
 */
enum IntakeOutcome: string
{
    use HasTranslatedLabel;

    case Pending = 'pending';
    case Processed = 'processed';
    case IgnoredWithReason = 'ignored_with_reason';
    case NeedsReview = 'needs_review';

    public static function translationGroup(): string
    {
        return 'enums.intake_outcome';
    }
}
