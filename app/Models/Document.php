<?php

namespace App\Models;

use App\Domain\Documents\DocumentKind;
use App\Domain\Documents\DocumentStatus;
use App\Domain\Documents\ReviewReason;
use Database\Factories\DocumentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A stored file, identified by the SHA-256 of its original bytes.
 *
 * @property int $id
 * @property string $uuid
 * @property int|null $candidate_id
 * @property string|null $path
 * @property string $sha256
 * @property string $mime
 * @property string $extension
 * @property int $size
 * @property DocumentKind $kind
 * @property DocumentStatus $status
 * @property ReviewReason|null $review_reason
 * @property bool $encrypted
 * @property int|null $page_count
 * @property array<string, mixed>|null $quality_flags
 * @property string|null $quality_score
 * @property-read Candidate|null $candidate
 * @property-read Extraction|null $latestExtraction
 */
class Document extends Model
{
    /** @use HasFactory<DocumentFactory> */
    use HasFactory;

    protected $fillable = [
        'uuid',
        'candidate_id',
        'path',
        'sha256',
        'mime',
        'extension',
        'size',
        'kind',
        'status',
        'review_reason',
        'encrypted',
        'page_count',
        'quality_flags',
        'quality_score',
    ];

    protected function casts(): array
    {
        return [
            'kind' => DocumentKind::class,
            'status' => DocumentStatus::class,
            'review_reason' => ReviewReason::class,
            'encrypted' => 'boolean',
            'quality_flags' => 'array',
            'size' => 'integer',
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
     * @return HasMany<DocumentSource, $this>
     */
    public function sources(): HasMany
    {
        return $this->hasMany(DocumentSource::class);
    }

    /**
     * @return HasMany<Extraction, $this>
     */
    public function extractions(): HasMany
    {
        return $this->hasMany(Extraction::class);
    }

    /**
     * @return HasOne<Extraction, $this>
     */
    public function latestExtraction(): HasOne
    {
        return $this->hasOne(Extraction::class)->latestOfMany();
    }
}
