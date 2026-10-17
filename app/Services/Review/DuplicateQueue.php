<?php

namespace App\Services\Review;

use App\Domain\Access\Permission;
use App\Models\Candidate;
use App\Models\DuplicateResolution;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

final class DuplicateQueue
{
    /** @return Builder<Candidate> */
    public function candidates(User $user): Builder
    {
        $visible = app(ReviewApplications::class)->visible($user)->select('applications.id');

        return Candidate::query()->whereNull('merged_into_id')->whereHas('applications')
            ->whereDoesntHave('applications', fn (Builder $q) => $q->whereNotIn('applications.id', $visible));
    }

    public function canResolve(User $user, Candidate $existing, Candidate $incoming): bool
    {
        return $user->isUsable() && $user->hasPermission(Permission::ReviewManage) && $existing->id !== $incoming->id
            && $this->candidates($user)->whereKey([$existing->id, $incoming->id])->count() === 2 && $this->matches($existing, $incoming);
    }

    public function matches(Candidate $a, Candidate $b): bool
    {
        return ($a->email_hmac !== null && $a->email_hmac === $b->email_hmac)
            || ($a->phone_hmac !== null && $a->phone_hmac === $b->phone_hmac);
    }

    public function fingerprint(Candidate $a, Candidate $b): string
    {
        $items = [$a, $b];
        usort($items, fn (Candidate $x, Candidate $y): int => $x->id <=> $y->id);

        return hash('sha256', json_encode(array_map(fn (Candidate $c): array => [$c->id, $c->active_document_id, $c->name, $c->email_hmac, $c->phone_hmac], $items), JSON_THROW_ON_ERROR));
    }

    /** @return list<array{existing:Candidate,incoming:Candidate}> */
    public function pairs(User $user): array
    {
        abort_unless($user->isUsable() && $user->hasPermission(Permission::ReviewManage), 403);
        $ids = $this->candidates($user)->select('candidates.id');
        $pairs = Candidate::query()->select('candidates.*', 'incoming.id as incoming_id')
            ->join('candidates as incoming', function ($join) {
                $join->on('incoming.id', '>', 'candidates.id')->where(function ($query) {
                    $query->whereColumn('incoming.email_hmac', 'candidates.email_hmac')->orWhereColumn('incoming.phone_hmac', 'candidates.phone_hmac');
                });
            })->whereIn('candidates.id', $ids)->whereIn('incoming.id', $ids)->orderBy('candidates.id')->orderBy('incoming.id');
        $result = [];
        foreach ($pairs->cursor() as $existing) {
            $incoming = Candidate::query()->with(['activeDocument', 'applications.job'])->findOrFail($existing->getAttribute('incoming_id'));
            if (DuplicateResolution::query()->where('fingerprint', $this->fingerprint($existing, $incoming))->exists()) {
                continue;
            }
            $existing->load(['activeDocument', 'applications.job']);
            $result[] = ['existing' => $existing, 'incoming' => $incoming];
            if (count($result) === 25) {
                break;
            }
        }

        return $result;
    }
}
