<?php

namespace App\Services\Review;

use App\Domain\Criteria\CriterionType;
use App\Models\Application;
use App\Models\User;

final readonly class TopApplications
{
    public function __construct(private ReviewApplications $reviews, private EvidenceRedactor $redactor) {}

    /** @param array<int,string> $jobs
     * @return list<array<string,mixed>> */
    public function cards(User $user, array $jobs, ?int $jobId = null): array
    {
        abort_unless($user->isUsable() && $user->can('viewAny', Application::class), 403);
        $cards = [];
        foreach ($jobs as $id => $title) {
            if ($jobId !== null && $jobId !== $id) {
                continue;
            }
            $entries = $this->reviews->ranked($user, ['job' => $id])
                ->with('currentScore.criterionResults.criterion')->limit(3)->get()
                ->map(fn (Application $application): array => $this->entry($user, $application))->all();
            $cards[] = ['job_id' => $id, 'title' => $title, 'entries' => $entries];
        }

        return $cards;
    }

    /** @return array<string,mixed> */
    private function entry(User $user, Application $application): array
    {
        $score = $application->currentScore;
        $missing = [];
        $quotes = [];
        foreach ($score->criterionResults->sortByDesc('confidence') as $criterionResult) {
            $criterion = $criterionResult->criterion;
            $label = app()->getLocale() === 'ar' ? $criterion->label_ar : $criterion->label_en;
            if ($criterion->type === CriterionType::MustHave && $criterionResult->result !== 'met') {
                $missing[] = ['label' => $label, 'outcome' => $criterionResult->result];
            }
            if ($criterionResult->result !== 'met') {
                continue;
            }
            foreach (json_decode($criterionResult->evidence_enc ?? '[]', true, 512, JSON_THROW_ON_ERROR) as $evidence) {
                $quote = (string) ($evidence['quote'] ?? '');
                if ($this->reviews->blind($user, $application)) {
                    $quote = $this->redactor->redact($quote, $application);
                }
                if ($quote !== '' && ! isset($quotes[$quote])) {
                    $quotes[$quote] = ['quote' => $quote, 'source' => $criterionResult->source];
                }
            }
        }

        return ['id' => $application->id, 'identity' => $this->reviews->identity($user, $application),
            'score' => $score->score, 'band' => $score->band, 'coverage' => $score->coverage,
            'quotes' => array_slice(array_values($quotes), 0, 3), 'missing' => $missing];
    }
}
