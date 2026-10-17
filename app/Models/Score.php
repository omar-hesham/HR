<?php

namespace App\Models;

use Database\Factories\ScoreFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One evaluation of an application against one criteria version (Milestone 3).
 * Never overwritten: re-scoring creates a new row (ARC-P0-02).
 *
 * @property int $id
 * @property int $application_id
 * @property int $criteria_version_id
 * @property int|null $extraction_id
 * @property string|null $score
 * @property string|null $band
 * @property string|null $coverage
 * @property string|null $reason_enc
 * @property string $rules_version
 * @property string|null $schema_version
 * @property string|null $prompt_version
 * @property string|null $model_id
 * @property string|null $driver
 */
class Score extends Model
{
    /** @use HasFactory<ScoreFactory> */
    use HasFactory;

    protected $fillable = [
        'application_id', 'criteria_version_id', 'extraction_id', 'score', 'band', 'coverage',
        'reason_enc', 'rules_version', 'schema_version', 'prompt_version', 'model_id', 'driver',
    ];

    protected function casts(): array
    {
        return ['reason_enc' => 'encrypted', 'score' => 'decimal:2', 'coverage' => 'decimal:2'];
    }

    /**
     * @return BelongsTo<Application, $this>
     */
    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    /**
     * @return BelongsTo<CriteriaVersion, $this>
     */
    public function criteriaVersion(): BelongsTo
    {
        return $this->belongsTo(CriteriaVersion::class);
    }

    /**
     * @return BelongsTo<Extraction, $this>
     */
    public function extraction(): BelongsTo
    {
        return $this->belongsTo(Extraction::class);
    }

    /**
     * @return HasMany<CriterionResult, $this>
     */
    public function criterionResults(): HasMany
    {
        return $this->hasMany(CriterionResult::class);
    }

    /**
     * @return HasMany<Override, $this>
     */
    public function overrides(): HasMany
    {
        return $this->hasMany(Override::class);
    }
}
