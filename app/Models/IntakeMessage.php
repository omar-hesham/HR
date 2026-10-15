<?php

namespace App\Models;

use App\Domain\Documents\SourceChannel;
use Database\Factories\IntakeMessageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One received message; the raw .eml and its hash are stored before parsing (kickoff §5.2, Milestone 2).
 *
 * @property int $id
 * @property int|null $mailbox_id
 * @property SourceChannel $source
 * @property string $external_id
 * @property string|null $message_id_header
 * @property string|null $raw_path
 * @property string|null $raw_sha256
 * @property int|null $raw_size
 * @property string|null $subject_enc
 * @property string|null $sender_hmac
 * @property Carbon $received_at
 * @property Carbon|null $visible_at
 * @property string $outcome
 * @property string|null $reason
 * @property int|null $job_hint_id
 * @property string|null $last_error
 */
class IntakeMessage extends Model
{
    /** @use HasFactory<IntakeMessageFactory> */
    use HasFactory;

    protected $fillable = [
        'mailbox_id', 'source', 'external_id', 'message_id_header', 'raw_path', 'raw_sha256', 'raw_size',
        'subject_enc', 'sender_hmac', 'received_at', 'visible_at', 'outcome', 'reason', 'job_hint_id', 'last_error',
    ];

    protected function casts(): array
    {
        return [
            'source' => SourceChannel::class,
            'subject_enc' => 'encrypted',
            'received_at' => 'datetime',
            'visible_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Mailbox, $this>
     */
    public function mailbox(): BelongsTo
    {
        return $this->belongsTo(Mailbox::class);
    }

    /**
     * @return HasMany<DocumentSource, $this>
     */
    public function documentSources(): HasMany
    {
        return $this->hasMany(DocumentSource::class);
    }
}
