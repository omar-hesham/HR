<?php

namespace App\Models;

use App\Domain\Applications\ApplicationStatus;
use Database\Factories\StatusEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Append-only status history (WF-P0-02). user_id null = background worker.
 *
 * @property int $id
 * @property int $application_id
 * @property ApplicationStatus|null $from_status
 * @property ApplicationStatus $to_status
 * @property int|null $user_id
 * @property string|null $reason_code
 * @property string|null $reason_enc
 * @property Carbon $created_at
 */
class StatusEvent extends Model
{
    /** @use HasFactory<StatusEventFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = ['application_id', 'from_status', 'to_status', 'user_id', 'reason_code', 'reason_enc', 'created_at'];

    protected function casts(): array
    {
        return [
            'from_status' => ApplicationStatus::class,
            'to_status' => ApplicationStatus::class,
            'reason_enc' => 'encrypted',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn (StatusEvent $event) => throw Exceptions\ImmutableRecordException::forModel($event));
    }

    /**
     * @return BelongsTo<Application, $this>
     */
    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
