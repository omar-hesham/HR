<?php

namespace App\Actions\Scoring;

use App\Domain\Access\Permission;
use App\Models\Override;
use App\Models\Score;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class OverrideScore
{
    public function handle(Score $score, string $value, User $actor, string $reason): Override
    {
        abort_unless($actor->can('view', $score->application) && $actor->hasPermission(Permission::ScoresOverride), 403);
        abort_if(trim($reason) === '' || ! is_numeric($value) || (float) $value < 0 || (float) $value > 100, 422);

        return DB::transaction(function () use ($score, $value, $actor, $reason): Override {
            $locked = Score::query()->whereKey($score->id)->lockForUpdate()->firstOrFail();
            $previous = $locked->overrides()->latest('id')->first();
            $override = $locked->overrides()->create(['user_id' => $actor->id, 'old_value' => $previous->new_value ?? $locked->score, 'new_value' => number_format((float) $value, 2, '.', ''), 'reason_enc' => $reason]);
            activity('review')->causedBy($actor)->performedOn($locked)->event('score.overridden')->withProperties(['override_id' => $override->id])->log('score.overridden');

            return $override;
        });
    }
}
