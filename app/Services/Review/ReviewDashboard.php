<?php

namespace App\Services\Review;

use App\Models\Application;
use App\Models\RecruitmentJob;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

final class ReviewDashboard
{
    /** @return array<int,string> */
    public function jobs(User $user): array
    {
        return RecruitmentJob::query()->whereIn('id', app(ReviewApplications::class)->visible($user)->select('recruitment_job_id'))
            ->orderBy('id')->get()->mapWithKeys(fn (RecruitmentJob $job): array => [$job->id => $job->title()])->all();
    }

    /** @param array<string,mixed> $filters
     * @return array<string,mixed> */
    public function data(User $user, array $filters): array
    {
        abort_unless($user->isUsable() && $user->can('viewAny', Application::class), 403);
        $reviews = app(ReviewApplications::class);

        $jobs = $this->jobs($user);

        return [
            'jobs' => $jobs,
            'top' => app(TopApplications::class)->cards($user, $jobs, is_numeric($filters['job'] ?? null) ? (int) $filters['job'] : null),
            'ranked' => $reviews->ranked($user, $filters)->paginate(25, ['applications.*'], 'rankPage'),
            'exceptions' => $reviews->exceptions($user)->whereNotIn('applications.id', $reviews->stale($user)->select('applications.id'))->paginate(25, ['applications.*'], 'exceptionPage'),
            'rescore' => $reviews->stale($user)->with(['candidate', 'job', 'currentScore.criteriaVersion', 'currentScore.overrides', 'scores.criteriaVersion'])->orderBy('received_at')->orderBy('id')->paginate(25, ['applications.*'], 'rescorePage'),
            'unscored_count' => $reviews->visible($user)->whereDoesntHave('currentScore', fn (Builder $q) => $q->whereNotNull('score'))->count(),
            'needs_review_count' => $reviews->visible($user)->where(fn (Builder $query) => $query->where('status', 'needs_review')->orWhereHas('currentScore', fn (Builder $score) => $score->where('band', 'needs_review')))->count(),
            'stale_count' => $reviews->stale($user)->count(),
        ];
    }
}
