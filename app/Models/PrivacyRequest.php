<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Data-subject request (PRIV-P0-04, Milestone 4).
 *
 * @property int $id
 * @property int|null $candidate_id
 * @property string $type
 * @property string $status
 */
class PrivacyRequest extends Model
{
    protected $fillable = ['candidate_id', 'type', 'status', 'details_enc', 'verified_at', 'approved_by', 'completed_at', 'requested_by', 'verification_enc', 'decision_enc', 'channel_enc', 'notification_enc', 'notified_at', 'decided_at'];

    protected function casts(): array
    {
        return [
            'details_enc' => 'encrypted',
            'verification_enc' => 'encrypted', 'decision_enc' => 'encrypted', 'channel_enc' => 'encrypted', 'notification_enc' => 'encrypted',
            'notified_at' => 'datetime', 'decided_at' => 'datetime',
            'verified_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Candidate, $this>
     */
    public function candidate(): BelongsTo
    {
        return $this->belongsTo(Candidate::class);
    }
}
