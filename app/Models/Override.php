<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Human score override with mandatory reason (M7-P0-03, Milestone 4).
 *
 * @property int $id
 * @property int $score_id
 * @property int $user_id
 * @property string|null $old_value
 * @property string|null $new_value
 * @property string $reason_enc
 */
class Override extends Model
{
    protected $fillable = ['score_id', 'user_id', 'old_value', 'new_value', 'reason_enc'];

    protected function casts(): array
    {
        return ['reason_enc' => 'encrypted'];
    }

    /**
     * @return BelongsTo<Score, $this>
     */
    public function score(): BelongsTo
    {
        return $this->belongsTo(Score::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
