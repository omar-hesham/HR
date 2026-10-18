<?php

namespace App\Services\Review;

use App\Models\Application;
use App\Models\Score;
use App\Models\User;
use Illuminate\Validation\ValidationException;

final class CompareApplications
{
    /** @param list<int|string> $ids
     * @return list<array<string,mixed>> */
    public function data(User $user, array $ids, ?int $version = null): array
    {
        $ids = array_values(array_unique($ids));
        if (count($ids) < 2 || count($ids) > 3 || count(array_filter($ids, fn ($id): bool => is_numeric($id))) !== count($ids)) {
            throw ValidationException::withMessages(['selection' => __('review.compare_invalid')]);
        }
        $result = [];
        $job = null;
        $criteria = null;
        foreach ($ids as $id) {
            $application = Application::query()->with(['candidate', 'job', 'currentScore'])->findOrFail($id);
            abort_unless($user->isUsable() && $user->can('view', $application) && $application->status->value !== 'deleted', 403);
            $score = $version === null ? $application->currentScore : $application->scores()->where('criteria_version_id', $version)->latest('id')->first();
            if (! $score instanceof Score || $application->job === null || ($job !== null && $job !== $application->recruitment_job_id)
                || ($criteria !== null && $criteria !== $score->criteria_version_id)
                || $score->criteriaVersion->recruitment_job_id !== $application->recruitment_job_id) {
                throw ValidationException::withMessages(['selection' => __('review.compare_invalid')]);
            }
            $job = $application->recruitment_job_id;
            $criteria = $score->criteria_version_id;
            $blind = app(ReviewApplications::class)->blind($user, $application);
            $data = app(ScorePresentation::class)->data($score);
            // Never send raw profile, explanation, document preview or models to a blind comparison view.
            $rows = [];
            foreach ($data['rows'] as $row) {
                $quotes = [];
                foreach ($row['evidence'] as $evidence) {
                    $quote = (string) ($evidence['quote'] ?? '');
                    if ($blind) {
                        $quote = app(EvidenceRedactor::class)->redact($quote, $application);
                    }
                    $quotes[] = ['quote' => $quote, 'document_id' => $evidence['document_id'] ?? null, 'start' => $evidence['start'] ?? null, 'end' => $evidence['end'] ?? null];
                }
                $rows[] = ['label' => app()->getLocale() === 'ar' ? $row['criterion']->label_ar : $row['criterion']->label_en,
                    'result' => $row['result'], 'source' => $row['source'], 'confidence' => $row['confidence'], 'evidence' => $quotes];
            }
            $result[] = ['id' => $application->id, 'identity' => app(ReviewApplications::class)->identity($user, $application),
                'email' => $blind ? null : $application->candidate->email, 'phone' => $blind ? null : $application->candidate->phone,
                'band' => $score->band, 'coverage' => $score->coverage, 'score' => $score->score,
                'version' => $score->criteriaVersion->version, 'rows' => $rows];
        }

        return $result;
    }
}
