<?php

/**
 * WF-P0-02 / D-91: no automatic rejection; reasons for rejection and reopening.
 */

use App\Domain\Applications\ApplicationStatus as S;
use App\Domain\Applications\Exceptions\InvalidStatusTransition;
use App\Domain\Applications\StatusTransitionGuard;

it('lets a background worker move received → processing → needs review', function () {
    $guard = new StatusTransitionGuard;

    $guard->assertAllowed(S::Received, S::Processing, false, null);
    $guard->assertAllowed(S::Processing, S::NeedsReview, false, null);

    expect(true)->toBeTrue();
});

it('never lets a background worker reject, shortlist or decide', function (S $from, S $to) {
    (new StatusTransitionGuard)->assertAllowed($from, $to, false, 'automatic');
})->with([
    [S::Scored, S::RejectedByHuman],
    [S::NeedsReview, S::RejectedByHuman],
    [S::Scored, S::Shortlisted],
    [S::Interview, S::Hired],
])->throws(InvalidStatusTransition::class);

it('requires a reason for a human rejection and for reopening it', function (S $from, S $to) {
    (new StatusTransitionGuard)->assertAllowed($from, $to, true, '  ');
})->with([
    [S::Scored, S::RejectedByHuman],
    [S::RejectedByHuman, S::NeedsReview],
])->throws(InvalidStatusTransition::class);

it('accepts a reasoned human rejection', function () {
    (new StatusTransitionGuard)->assertAllowed(S::Shortlisted, S::RejectedByHuman, true, 'Not available for night shifts');

    expect(true)->toBeTrue();
});

it('rejects transitions outside the lifecycle diagram', function () {
    (new StatusTransitionGuard)->assertAllowed(S::Received, S::Hired, true, 'x');
})->throws(InvalidStatusTransition::class);

it('marks active statuses that retention must never delete automatically', function () {
    expect(S::Shortlisted->isActive())->toBeTrue()
        ->and(S::NeedsReview->isActive())->toBeTrue()
        ->and(S::RejectedByHuman->isActive())->toBeFalse()
        ->and(S::Hired->isFinalDecision())->toBeTrue();
});
