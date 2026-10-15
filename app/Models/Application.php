<?php

namespace App\Models;

use App\Domain\Applications\ApplicationStatus;
use App\Domain\Documents\ReviewReason;
use App\Domain\Documents\SourceChannel;
use App\Domain\Privacy\RetentionTrack;
use Database\Factories\ApplicationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A candidate's application to one job (or awaiting job assignment).
 *
 * @property int $id
 * @property string $uuid
 * @property int $candidate_id
 * @property int|null $recruitment_job_id
 * @property SourceChannel $source
 * @property string|null $external_reference
 * @property ApplicationStatus $status
 * @property ReviewReason|null $review_reason
 * @property Carbon $received_at
 * @property Carbon $last_activity_at
 * @property Carbon|null $final_decision_at
 * @property Carbon|null $retention_due_at
 * @property RetentionTrack $retention_track
 * @property Carbon|null $handed_over_at
 * @property int|null $current_score_id
 * @property int|null $created_by
 * @property-read Candidate $candidate
 * @property-read RecruitmentJob|null $job
 */
class Application extends Model
{
    /** @use HasFactory<ApplicationFactory> */
    use HasFactory;

    protected $fillable = [
        'uuid',
        'candidate_id',
        'recruitment_job_id',
        'source',
        'external_reference',
        'status',
        'review_reason',
        'received_at',
        'last_activity_at',
        'final_decision_at',
        'retention_due_at',
        'retention_track',
        'handed_over_at',
        'current_score_id',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'source' => SourceChannel::class,
            'status' => ApplicationStatus::class,
            'review_reason' => ReviewReason::class,
            'retention_track' => RetentionTrack::class,
            'received_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'final_decision_at' => 'datetime',
            'retention_due_at' => 'datetime',
            'handed_over_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Candidate, $this>
     */
    public function candidate(): BelongsTo
    {
        return $this->belongsTo(Candidate::class);
    }

    /**
     * @return BelongsTo<RecruitmentJob, $this>
     */
    public function job(): BelongsTo
    {
        return $this->belongsTo(RecruitmentJob::class, 'recruitment_job_id');
    }

    /**
     * @return HasMany<DocumentSource, $this>
     */
    public function sources(): HasMany
    {
        return $this->hasMany(DocumentSource::class);
    }

    /**
     * @return HasMany<StatusEvent, $this>
     */
    public function statusEvents(): HasMany
    {
        return $this->hasMany(StatusEvent::class);
    }

    /**
     * @return HasMany<Note, $this>
     */
    public function notes(): HasMany
    {
        return $this->hasMany(Note::class);
    }

    /**
     * @return HasMany<Score, $this>
     */
    public function scores(): HasMany
    {
        return $this->hasMany(Score::class);
    }
}
