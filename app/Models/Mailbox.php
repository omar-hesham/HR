<?php

namespace App\Models;

use Database\Factories\MailboxFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Recruitment mailbox connection (Milestone 2). Secrets use an encrypted credentials field or a non-secret config reference.
 *
 * @property string|null $display_name
 * @property string|null $folder
 * @property int $id
 * @property string $provider
 * @property string $address
 * @property string|null $host
 * @property int|null $port
 * @property string|null $encryption
 * @property string $auth_type
 * @property array<string, string>|null $credentials
 * @property string $credential_ref
 * @property string|null $cursor
 * @property string|null $uidvalidity
 * @property Carbon|null $start_at
 * @property Carbon|null $last_success_at
 * @property Carbon|null $last_message_at
 * @property string|null $last_error
 * @property int|null $silence_alert_hours
 * @property Carbon|null $last_silence_alert_at
 * @property bool $active
 */
class Mailbox extends Model
{
    /** @use HasFactory<MailboxFactory> */
    use HasFactory;

    protected $fillable = [
        'display_name', 'folder', 'provider', 'address', 'host', 'port', 'encryption', 'auth_type', 'credential_ref', 'cursor',
        'uidvalidity', 'start_at', 'last_success_at', 'last_message_at', 'last_error', 'silence_alert_hours',
        'last_silence_alert_at', 'active', 'credentials',
    ];

    protected static function booted(): void
    {
        static::creating(function (Mailbox $mailbox): void {
            $mailbox->start_at ??= now();
        });
    }

    protected $hidden = ['credentials'];

    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array',
            'start_at' => 'datetime',
            'last_success_at' => 'datetime',
            'last_message_at' => 'datetime',
            'last_silence_alert_at' => 'datetime',
            'active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<IntakeMessage, $this>
     */
    public function intakeMessages(): HasMany
    {
        return $this->hasMany(IntakeMessage::class);
    }
}
