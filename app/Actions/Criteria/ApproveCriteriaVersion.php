<?php

namespace App\Actions\Criteria;

use App\Domain\Criteria\CriteriaValidator;
use App\Domain\Criteria\CriteriaVersionStatus;
use App\Models\CriteriaVersion;
use App\Models\Criterion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Approves a draft (M4-P0-02): validates the whole version, freezes it, supersedes the
 * previous approved version (kept, read-only) and records that applications scored with
 * older versions need re-scoring (scores themselves are never overwritten; Milestone 3).
 */
final class ApproveCriteriaVersion
{
    public function __construct(private readonly CriteriaValidator $validator) {}

    public function handle(CriteriaVersion $version, User $actor): CriteriaVersion
    {
        Gate::forUser($actor)->authorize('approve', $version);

        $criteria = $version->criteria()->get()->map(fn (Criterion $c) => $c->toData())->values()->all();
        $result = $this->validator->validateVersion($criteria);

        if (! $result->passes()) {
            throw new CriteriaValidationException($result);
        }

        return DB::transaction(function () use ($version, $actor): CriteriaVersion {
            $job = $version->job()->lockForUpdate()->firstOrFail();
            $previous = $job->currentCriteriaVersion;

            if ($previous !== null && $previous->id !== $version->id) {
                $previous->status = CriteriaVersionStatus::Superseded;
                $previous->save();
            }

            $version->forceFill([
                'status' => CriteriaVersionStatus::Approved,
                'approved_by' => $actor->id,
                'approved_at' => now(),
            ])->save();

            $job->forceFill(['current_criteria_version_id' => $version->id])->save();

            activity('configuration')
                ->causedBy($actor)
                ->performedOn($version)
                ->event('criteria.approved')
                ->withProperties([
                    'job_id' => $job->id,
                    'version' => $version->version,
                    'superseded_version' => $previous?->version,
                    'rescore_required' => $previous !== null,
                ])
                ->log('criteria.approved');

            return $version->refresh();
        });
    }
}
