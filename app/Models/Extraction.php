<?php

namespace App\Models;

use App\Casts\EncryptedContent;
use App\Domain\Text\NormalizedText;
use Database\Factories\ExtractionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Text extracted from a document. Raw text, normalized text, offset map and
 * structured fields are encrypted with the content key (SEC-P0-02).
 *
 * @property int $id
 * @property int $document_id
 * @property string $extractor
 * @property string $extractor_version
 * @property string $status
 * @property string|null $error_code
 * @property string|null $raw_text_enc
 * @property string|null $normalized_text_enc
 * @property string|null $offset_map_enc
 * @property string|null $fields_json_enc
 * @property string|null $schema_version
 * @property int|null $char_count
 * @property int $presentation_forms_replaced
 * @property array<string, mixed>|null $quality_metrics
 * @property-read Document $document
 */
class Extraction extends Model
{
    /** @use HasFactory<ExtractionFactory> */
    use HasFactory;

    public const STATUS_OK = 'ok';

    public const STATUS_NEEDS_REVIEW = 'needs_review';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'document_id',
        'extractor',
        'extractor_version',
        'status',
        'error_code',
        'raw_text_enc',
        'normalized_text_enc',
        'offset_map_enc',
        'fields_json_enc',
        'schema_version',
        'char_count',
        'presentation_forms_replaced',
        'quality_metrics',
    ];

    protected function casts(): array
    {
        return [
            'raw_text_enc' => EncryptedContent::class,
            'normalized_text_enc' => EncryptedContent::class,
            'offset_map_enc' => EncryptedContent::class,
            'fields_json_enc' => EncryptedContent::class,
            'quality_metrics' => 'array',
            'char_count' => 'integer',
            'presentation_forms_replaced' => 'integer',
        ];
    }

    public function rawText(): ?string
    {
        return $this->raw_text_enc;
    }

    public function normalizedText(): ?string
    {
        return $this->normalized_text_enc;
    }

    public function normalized(): ?NormalizedText
    {
        if ($this->normalized_text_enc === null) {
            return null;
        }

        /** @var list<array{0: int, 1: int}> $runs */
        $runs = json_decode($this->offset_map_enc ?? '[]', true) ?: [];

        return new NormalizedText($this->normalized_text_enc, $runs, $this->presentation_forms_replaced);
    }

    /**
     * @return BelongsTo<Document, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
