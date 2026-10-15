<?php

namespace App\Domain\Applications;

use App\Domain\Applications\Exceptions\InvalidStatusTransition;

/**
 * Pure rules for application status changes (WF-P0-02, M7-P0-04).
 * Who may act is decided by ApplicationPolicy; this class decides what is possible at all.
 */
final class StatusTransitionGuard
{
    public function assertAllowed(ApplicationStatus $from, ApplicationStatus $to, bool $byHuman, ?string $reason): void
    {
        if (! in_array($to, $from->allowedNext(), true)) {
            throw InvalidStatusTransition::notAllowed($from, $to);
        }

        if (! $byHuman && $from->requiresHuman($to)) {
            throw InvalidStatusTransition::humanRequired($from, $to);
        }

        if ($from->requiresReason($to) && trim((string) $reason) === '') {
            throw InvalidStatusTransition::reasonRequired($from, $to);
        }
    }
}
