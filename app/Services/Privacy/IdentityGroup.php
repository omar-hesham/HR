<?php

namespace App\Services\Privacy;

use App\Models\Candidate;

final class IdentityGroup
{
    /** @return list<int> */
    public function ids(int $candidateId): array
    {
        $ids = [$candidateId];
        do {
            $previous = $ids;
            $parents = Candidate::query()->whereIn('id', $ids)->whereNotNull('merged_into_id')->pluck('merged_into_id')->all();
            $children = Candidate::query()->whereIn('merged_into_id', $ids)->pluck('id')->all();
            $ids = array_values(array_unique([...$ids, ...$parents, ...$children]));
        } while ($ids !== $previous);
        sort($ids);

        return $ids;
    }
}
