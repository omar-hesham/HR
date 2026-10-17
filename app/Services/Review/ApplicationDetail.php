<?php

namespace App\Services\Review;

use App\Models\Application;
use App\Models\FieldCorrection;
use App\Models\User;

final class ApplicationDetail
{
    /** @return array<string,mixed> */
    public function data(User $user, Application $application, ?int $scoreId = null): array
    {
        $application->refresh();
        abort_unless($user->isUsable() && $user->can('view', $application) && $application->status->value !== 'deleted', 403);
        $application->load(['candidate.activeDocument.latestExtraction', 'job', 'currentScore']);
        $scores = $application->scores()->with(['criteriaVersion', 'overrides.user'])->latest('id')->paginate(10, ['*'], 'scorePage');
        activity('access')->causedBy($user)->performedOn($application)->event('application.viewed')
            ->withProperties(['purpose' => 'candidate_review'])->log('application.viewed');

        $selectedScore = $scoreId === null ? $application->currentScore : $application->scores()->findOrFail($scoreId);

        return ['application' => $application, 'document' => $application->candidate->activeDocument,
            'score' => $selectedScore === null ? null : app(ScorePresentation::class)->data($selectedScore),
            'scores' => $scores, 'notes' => $application->notes()->with('user')->latest('id')->paginate(20, ['*'], 'notePage'),
            'events' => $application->statusEvents()->with('user')->latest('id')->paginate(30, ['*'], 'eventPage'),
            'corrections' => FieldCorrection::query()->where('application_id', $application->id)->latest('id')->paginate(20, ['*'], 'correctionPage')];
    }
}
