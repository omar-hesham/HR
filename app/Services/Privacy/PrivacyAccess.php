<?php

namespace App\Services\Privacy;

use App\Domain\Access\Permission;
use App\Models\Application;
use App\Models\Candidate;
use App\Models\PrivacyRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

final class PrivacyAccess
{
    public function allowed(User $user): bool
    {
        return $user->isUsable() && ($user->hasPermission(Permission::CandidatesDeleteApprove) || $user->hasPermission(Permission::CandidatesDeleteRequest));
    }

    public function approver(User $user): bool
    {
        return $user->isUsable() && $user->hasPermission(Permission::CandidatesDeleteApprove);
    }

    public function subject(User $user, Candidate $candidate): void
    {
        abort_unless($this->allowed($user) && $candidate->getAttribute('erased_at') === null, 403);
        $applications = Application::query()->whereIn('candidate_id', app(IdentityGroup::class)->ids($candidate->id))->where('status', '!=', 'deleted')->get();
        abort_if($applications->isEmpty(), 403);
        foreach ($applications as $application) {
            abort_unless($user->can('view', $application), 403);
        }
    }

    /** @return Builder<PrivacyRequest> */
    public function requests(User $user): Builder
    {
        abort_unless($this->allowed($user), 403);

        return PrivacyRequest::query()->when(! $this->approver($user), fn (Builder $q) => $q->where('requested_by', $user->id));
    }

    public function request(User $user, PrivacyRequest $request): void
    {
        abort_unless($this->requests($user)->whereKey($request->id)->exists(), 403);
        if ($request->candidate !== null) {
            $this->subject($user, $request->candidate);
        }
    }
}
