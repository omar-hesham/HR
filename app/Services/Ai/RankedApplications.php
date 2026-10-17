<?php

namespace App\Services\Ai;

use App\Models\Application;
use Illuminate\Database\Eloquent\Builder;

final class RankedApplications
{
    /**
     * @return Builder<Application> */
    public function query(int $jobId): Builder
    {
        return Application::query()->select('applications.*')->where('applications.recruitment_job_id', $jobId)
            ->join('recruitment_jobs as rank_job', 'rank_job.id', '=', 'applications.recruitment_job_id')
            ->leftJoin('scores as rank_score', function ($join) {
                $join->on('rank_score.id', '=', 'applications.current_score_id')->on('rank_score.criteria_version_id', '=', 'rank_job.current_criteria_version_id');
            })
            ->orderByRaw("CASE rank_score.band WHEN 'strong_match' THEN 0 WHEN 'needs_review' THEN 1 WHEN 'weak_match' THEN 2 ELSE 3 END")
            ->orderByDesc('rank_score.score')->orderByDesc('rank_score.coverage')->orderBy('applications.received_at')->orderBy('applications.id');
    }
}
