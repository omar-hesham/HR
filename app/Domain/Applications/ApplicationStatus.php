<?php

namespace App\Domain\Applications;

use App\Support\HasTranslatedLabel;

/**
 * Application lifecycle (spec §5.2, WF-P0-02).
 */
enum ApplicationStatus: string
{
    use HasTranslatedLabel;

    case Received = 'received';
    case Processing = 'processing';
    case Scored = 'scored';
    case NeedsReview = 'needs_review';
    case Shortlisted = 'shortlisted';
    case OnHold = 'on_hold';
    case RejectedByHuman = 'rejected_by_human';
    case Interview = 'interview';
    case Hired = 'hired';
    case NotHired = 'not_hired';
    case Archived = 'archived';
    case Deleted = 'deleted';

    public static function translationGroup(): string
    {
        return 'enums.application_status';
    }

    /**
     * Edges of the §5.2 state diagram (plus re-scoring Scored → Processing).
     *
     * @return list<self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Received => [self::Processing, self::NeedsReview, self::Deleted],
            self::Processing => [self::Scored, self::NeedsReview, self::Deleted],
            self::Scored => [self::Processing, self::Shortlisted, self::OnHold, self::RejectedByHuman, self::Deleted],
            self::NeedsReview => [self::Processing, self::Shortlisted, self::OnHold, self::RejectedByHuman, self::Deleted],
            self::Shortlisted => [self::OnHold, self::RejectedByHuman, self::Interview],
            self::OnHold => [self::NeedsReview, self::Archived],
            self::RejectedByHuman => [self::NeedsReview, self::Archived, self::Deleted],
            self::Interview => [self::Hired, self::NotHired, self::Deleted],
            self::Hired => [self::Archived, self::Deleted],
            self::NotHired => [self::Archived, self::Deleted],
            self::Archived => [self::Deleted],
            self::Deleted => [],
        };
    }

    /**
     * Decisions only a human may take; a background worker never can (D-91).
     */
    public function requiresHuman(self $to): bool
    {
        $humanTargets = [self::Shortlisted, self::OnHold, self::RejectedByHuman, self::Interview, self::Hired, self::NotHired, self::Archived];

        return in_array($to, $humanTargets, true)
            || ($this === self::RejectedByHuman && $to === self::NeedsReview)
            || ($this === self::OnHold && $to === self::NeedsReview);
    }

    /**
     * Rejection and reopening a rejection must carry a written reason.
     */
    public function requiresReason(self $to): bool
    {
        return $to === self::RejectedByHuman
            || ($this === self::RejectedByHuman && $to === self::NeedsReview);
    }

    public function isFinalDecision(): bool
    {
        return in_array($this, [self::Hired, self::NotHired, self::RejectedByHuman, self::Archived], true);
    }

    /**
     * Statuses never deleted by the ordinary retention date (PRIV-P0-03).
     */
    public function isActive(): bool
    {
        return in_array($this, [self::Processing, self::NeedsReview, self::Scored, self::Shortlisted, self::OnHold, self::Interview], true);
    }
}
