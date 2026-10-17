<?php

namespace App\Models;

use App\Domain\Criteria\CriteriaVersionStatus;
use App\Models\Exceptions\ImmutableRecordException;
use Database\Factories\CriteriaVersionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $recruitment_job_id
 * @property int $version
 * @property CriteriaVersionStatus $status
 * @property array<string, mixed>|null $thresholds
 * @property int|null $based_on_version_id
 * @property int|null $created_by
 * @property int|null $approved_by
 * @property Carbon|null $approved_at
 * @property string|null $change_note
 * @property-read RecruitmentJob $job
 */
class CriteriaVersion extends Model
{
    /** @use HasFactory<CriteriaVersionFactory> */
    use HasFactory;

    protected $fillable = [
        'recruitment_job_id',
        'version',
        'status',
        'thresholds',
        'based_on_version_id',
        'created_by',
        'change_note',
    ];

    protected function casts(): array
    {
        return [
            'status' => CriteriaVersionStatus::class,
            'thresholds' => 'array',
            'approved_at' => 'datetime',
        ];
    }

    /**
     * Approved versions are immutable (M4-P0-02). Only the status may move
     * approved → superseded when a newer version is approved.
     */
    protected static function booted(): void
    {
        static::updating(function (CriteriaVersion $version): void {
            $original = CriteriaVersionStatus::tryFrom((string) $version->getRawOriginal('status'));

            if ($original === CriteriaVersionStatus::Draft) {
                return;
            }

            $dirty = array_keys($version->getDirty());
            $onlySupersede = $dirty === ['status'] || array_diff($dirty, ['status', 'updated_at']) === [];

            if (! ($original === CriteriaVersionStatus::Approved && $onlySupersede && $version->status === CriteriaVersionStatus::Superseded)) {
                throw ImmutableRecordException::forModel($version);
            }
        });

        static::deleting(function (CriteriaVersion $version): void {
            if ($version->getRawOriginal('status') !== CriteriaVersionStatus::Draft->value) {
                throw ImmutableRecordException::forModel($version);
            }
        });
    }

    public function isEditable(): bool
    {
        return $this->status->isEditable();
    }

    /**
     * @return BelongsTo<RecruitmentJob, $this>
     */
    public function job(): BelongsTo
    {
        return $this->belongsTo(RecruitmentJob::class, 'recruitment_job_id');
    }

    /**
     * @return HasMany<Criterion, $this>
     */
    public function criteria(): HasMany
    {
        return $this->hasMany(Criterion::class)->orderBy('sort')->orderBy('id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
