<?php

namespace App\Models;

use App\Casts\EncryptedContent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Result of one criterion inside one score (Milestone 3).
 *
 * @property int $id
 * @property int $score_id
 * @property int $criterion_id
 * @property string $result
 * @property string|null $evidence_enc
 * @property string|null $normalizations_enc
 * @property string|null $confidence
 * @property string $source
 */
class CriterionResult extends Model
{
    protected $fillable = ['score_id', 'criterion_id', 'result', 'evidence_enc', 'normalizations_enc', 'confidence', 'source'];

    protected function casts(): array
    {
        return ['evidence_enc' => EncryptedContent::class, 'normalizations_enc' => EncryptedContent::class];
    }

    /**
     * @return BelongsTo<Score, $this>
     */
    public function score(): BelongsTo
    {
        return $this->belongsTo(Score::class);
    }

    /**
     * @return BelongsTo<Criterion, $this>
     */
    public function criterion(): BelongsTo
    {
        return $this->belongsTo(Criterion::class);
    }
}
