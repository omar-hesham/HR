<?php

namespace App\Models;

use App\Casts\EncryptedContent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $idempotency_key
 * @property string $purpose
 * @property int|null $document_id
 * @property int|null $application_id
 * @property int|null $intake_message_id
 * @property int|null $score_id
 * @property string $state
 * @property string|null $reason
 * @property int $attempts
 * @property Carbon|null $available_at
 * @property Carbon|null $queued_at
 * @property string|null $result_enc
 */
class AiWorkItem extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['available_at' => 'datetime', 'queued_at' => 'datetime', 'result_enc' => EncryptedContent::class, 'attempts' => 'integer'];
    }
}
