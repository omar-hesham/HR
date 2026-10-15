<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AI call metering (Milestone 3). No prompt or CV text is stored.
 *
 * @property int $id
 * @property int|null $document_id
 * @property string $purpose
 * @property string $driver
 * @property string $model
 * @property string $prompt_version
 * @property string $status
 */
class AiRun extends Model
{
    protected $fillable = [
        'document_id', 'purpose', 'driver', 'model', 'prompt_version', 'status', 'input_tokens', 'output_tokens',
        'cost_egp', 'reserved_cost_egp', 'duration_ms', 'error_code', 'request_id',
    ];

    /**
     * @return BelongsTo<Document, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
