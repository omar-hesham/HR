<?php

namespace App\Models;

use Database\Factories\CandidateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A person, decoupled from files and applications (M3). Name/contact/search
 * columns are plaintext by design (spec §9.3).
 *
 * @property int $id
 * @property string $uuid
 * @property string|null $name
 * @property string|null $email
 * @property string|null $phone
 * @property string|null $phone_original
 * @property string|null $email_hmac
 * @property string|null $phone_hmac
 * @property string|null $search_normalized
 * @property int|null $active_document_id
 * @property Carbon $received_at
 * @property Carbon|null $retention_hard_due_at
 * @property Carbon|null $retention_notice_at
 */
class Candidate extends Model
{
    /** @use HasFactory<CandidateFactory> */
    use HasFactory;

    protected $fillable = [
        'uuid',
        'name',
        'email',
        'phone',
        'phone_original',
        'email_hmac',
        'phone_hmac',
        'search_normalized',
        'active_document_id',
        'received_at',
        'retention_hard_due_at',
        'retention_notice_at',
    ];

    protected function casts(): array
    {
        return [
            'received_at' => 'datetime',
            'retention_hard_due_at' => 'datetime',
            'retention_notice_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<Document, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    /**
     * @return BelongsTo<Document, $this>
     */
    public function activeDocument(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'active_document_id');
    }

    /**
     * @return HasMany<Application, $this>
     */
    public function applications(): HasMany
    {
        return $this->hasMany(Application::class);
    }

    /**
     * Shown in lists until contacts are extracted (Milestone 3).
     */
    public function displayName(): string
    {
        return $this->name ?? __('app.candidates.provisional', ['ref' => substr($this->uuid, 0, 8)]);
    }
}
