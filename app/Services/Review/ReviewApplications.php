<?php

namespace App\Services\Review;

use App\Domain\Access\JobScope;
use App\Domain\Applications\ApplicationStatus;
use App\Domain\Privacy\NameBlindPolicy;
use App\Models\AiWorkItem;
use App\Models\Application;
use App\Models\User;
use App\Services\Ai\RankedApplications;
use Illuminate\Database\Eloquent\Builder;

final class ReviewApplications
{
    /** @return Builder<Application> */
    public function visible(User $user): Builder
    {
        return JobScope::applyToApplications(Application::query(), $user)
            ->where('applications.status', '!=', ApplicationStatus::Deleted->value);
    }

    /** @return Builder<Application> */
    public function current(User $user): Builder
    {
        return $this->visible($user)->whereHas('currentScore', function (Builder $scores) {
            $scores->whereNotNull('score')->whereHas('criteriaVersion.job', function (Builder $job) {
                $job->whereColumn('recruitment_jobs.current_criteria_version_id', 'scores.criteria_version_id')
                    ->whereColumn('recruitment_jobs.id', 'applications.recruitment_job_id');
            })->whereHas('extraction', function (Builder $extraction) {
                $extraction->whereHas('document', function (Builder $document) {
                    $document->whereHas('candidate', function (Builder $candidate) {
                        $candidate->whereColumn('candidates.active_document_id', 'documents.id')
                            ->whereColumn('candidates.id', 'applications.candidate_id');
                    });
                });
            });
        });
    }

    /** @return Builder<Application> */
    public function exceptions(User $user): Builder
    {
        return $this->visible($user)->where(function (Builder $query) use ($user) {
            $query->whereNotIn('applications.id', $this->current($user)->select('applications.id'))
                ->orWhere('applications.status', ApplicationStatus::NeedsReview->value)
                ->orWhereHas('currentScore', fn (Builder $score) => $score->where('band', 'needs_review'));
        })->with(['candidate', 'job', 'currentScore.criteriaVersion', 'currentScore.extraction', 'scores.criteriaVersion'])->orderBy('applications.received_at')->orderBy('applications.id');
    }

    /** @return Builder<Application> */
    public function stale(User $user): Builder
    {
        return $this->visible($user)->whereHas('currentScore', fn (Builder $q) => $q->whereNotNull('score'))
            ->whereNotIn('applications.id', $this->current($user)->select('applications.id'));
    }

    /** @param array<string,mixed> $filters
     * @return Builder<Application> */
    public function ranked(User $user, array $filters = []): Builder
    {
        $query = app(RankedApplications::class)->query(isset($filters['job']) && is_numeric($filters['job']) ? (int) $filters['job'] : null)
            ->whereIn('applications.id', $this->current($user)->select('applications.id'))
            ->with(['candidate', 'job', 'currentScore.criteriaVersion', 'currentScore.extraction', 'currentScore.overrides', 'scores.criteriaVersion']);
        if (in_array($filters['exception'] ?? '', ['awaiting_service', 'awaiting_budget'], true)) {
            $query->whereIn('applications.id', AiWorkItem::query()->where('reason', $filters['exception'])
                ->whereIn('state', ['waiting', 'queued', 'review'])->select('application_id'));
            unset($filters['exception']);
        }
        foreach (['band' => 'rank_score.band', 'status' => 'applications.status', 'source' => 'applications.source', 'exception' => 'applications.review_reason'] as $key => $column) {
            if (! empty($filters[$key])) {
                $query->where($column, $filters[$key]);
            }
        }
        if (($filters['job'] ?? '') === 'unassigned') {
            $query->whereNull('applications.recruitment_job_id');
        }
        foreach (['from' => '>=', 'until' => '<='] as $key => $operator) {
            if (! empty($filters[$key]) && is_string($filters[$key]) && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $filters[$key])) {
                $query->whereDate('applications.received_at', $operator, $filters[$key]);
            }
        }

        return $query;
    }

    public function isCurrent(Application $application): bool
    {
        $score = $application->currentScore;

        return $score !== null && $score->score !== null && $score->application_id === $application->id
            && $application->job?->current_criteria_version_id === $score->criteria_version_id
            && $score->extraction?->document_id === $application->candidate->active_document_id;
    }

    public function blind(User $user, Application $application): bool
    {
        return NameBlindPolicy::applies($user->name_blind_review, $application->job?->name_blind_review);
    }

    public function identity(User $user, Application $application): string
    {
        return $this->blind($user, $application)
            ? __('review.reference', ['ref' => substr($application->uuid, 0, 8)]) : $application->candidate->displayName();
    }
}
