<?php

namespace App\Models;

use App\Domain\Documents\Disposition;
use App\Domain\Documents\DocumentKind;
use App\Domain\Documents\ReviewReason;
use App\Domain\Documents\SourceChannel;
use Database\Factories\DocumentSourceFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One received file from one channel (WF-P0-01 accounting unit). Holds the
 * "Needs review" state so that rejected files without a document stay visible.
 *
 * @property int $id
 * @property int|null $document_id
 * @property int|null $intake_message_id
 * @property int|null $application_id
 * @property SourceChannel $source
 * @property string|null $external_reference
 * @property string $attachment_key
 * @property DocumentKind $attachment_kind
 * @property Disposition $disposition
 * @property string|null $reason
 * @property string|null $original_filename_enc
 * @property string|null $reported_mime
 * @property int|null $size
 * @property string|null $upload_batch
 * @property int|null $uploaded_by
 * @property ReviewReason|null $review_reason
 * @property Carbon|null $review_opened_at
 * @property Carbon|null $review_resolved_at
 * @property int|null $review_resolved_by
 * @property string|null $review_note_enc
 * @property Carbon $created_at
 * @property-read Document|null $document
 * @property-read IntakeMessage|null $intakeMessage
 * @property-read Application|null $application
 * @property-read User|null $uploader
 */
class DocumentSource extends Model
{
    /** @use HasFactory<DocumentSourceFactory> */
    use HasFactory;

    protected $fillable = [
        'document_id',
        'intake_message_id',
        'application_id',
        'source',
        'external_reference',
        'attachment_key',
        'attachment_kind',
        'disposition',
        'reason',
        'original_filename_enc',
        'reported_mime',
        'size',
        'upload_batch',
        'uploaded_by',
        'review_reason',
        'review_opened_at',
        'review_resolved_at',
        'review_resolved_by',
        'review_note_enc',
    ];

    protected function casts(): array
    {
        return [
            'source' => SourceChannel::class,
            'attachment_kind' => DocumentKind::class,
            'disposition' => Disposition::class,
            'review_reason' => ReviewReason::class,
            'original_filename_enc' => 'encrypted',
            'review_note_enc' => 'encrypted',
            'review_opened_at' => 'datetime',
            'review_resolved_at' => 'datetime',
            'size' => 'integer',
        ];
    }

    /**
     * @param  Builder<DocumentSource>  $query
     * @return Builder<DocumentSource>
     */
    public function scopeOpenReview(Builder $query): Builder
    {
        return $query->whereNotNull('review_reason')->whereNull('review_resolved_at');
    }

    public function openReview(ReviewReason $reason): void
    {
        $this->forceFill([
            'review_reason' => $reason,
            'review_opened_at' => now(),
            'review_resolved_at' => null,
            'review_resolved_by' => null,
        ])->save();
    }

    public function originalFilename(): ?string
    {
        return $this->original_filename_enc;
    }

    /**
     * @return BelongsTo<Document, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /**
     * @return BelongsTo<Application, $this>
     */
    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    /**
     * @return BelongsTo<IntakeMessage, $this>
     */
    public function intakeMessage(): BelongsTo
    {
        return $this->belongsTo(IntakeMessage::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'review_resolved_by');
    }
}
