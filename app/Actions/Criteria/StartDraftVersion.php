<?php

namespace App\Actions\Criteria;

use App\Domain\Criteria\CriteriaVersionStatus;
use App\Models\CriteriaVersion;
use App\Models\Criterion;
use App\Models\RecruitmentJob;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Editing criteria always happens in a new draft version (M4-P0-02). The draft starts
 * as a copy of the given (or current approved) version; approved versions stay untouched.
 */
final class StartDraftVersion
{
    public function handle(RecruitmentJob $job, User $actor, ?CriteriaVersion $basedOn = null): CriteriaVersion
    {
        Gate::forUser($actor)->authorize('createFor', [CriteriaVersion::class, $job]);

        return DB::transaction(function () use ($job, $actor, $basedOn): CriteriaVersion {
            $job->refresh();

            $existingDraft = $job->criteriaVersions()
                ->where('status', CriteriaVersionStatus::Draft->value)
                ->lockForUpdate()
                ->first();

            if ($existingDraft !== null) {
                return $existingDraft;
            }

            $basedOn ??= $job->currentCriteriaVersion;
            $next = (int) $job->criteriaVersions()->max('version') + 1;

            $draft = CriteriaVersion::query()->create([
                'recruitment_job_id' => $job->id,
                'version' => $next,
                'status' => CriteriaVersionStatus::Draft,
                'thresholds' => $basedOn !== null ? $basedOn->thresholds : ['strong' => 70, 'review' => 40, 'confidence' => 0.80],
                'based_on_version_id' => $basedOn?->id,
                'created_by' => $actor->id,
            ]);

            if ($basedOn !== null) {
                foreach ($basedOn->criteria as $criterion) {
                    Criterion::query()->create([
                        ...$criterion->only([
                            'type', 'field', 'operator', 'target_json', 'weight', 'label_ar', 'label_en',
                            'justification', 'legal_basis_note', 'sort',
                        ]),
                        'criteria_version_id' => $draft->id,
                    ]);
                }
            }

            activity('configuration')
                ->causedBy($actor)
                ->performedOn($draft)
                ->event('criteria.draft_started')
                ->withProperties(['job_id' => $job->id, 'version' => $next, 'based_on' => $basedOn?->version])
                ->log('criteria.draft_started');

            return $draft;
        });
    }
}
