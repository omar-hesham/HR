<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Privacy-notice outbox (M10-P0-01, Milestone 2). One row per idempotency key.
 *
 * @property int $id
 * @property string $status
 */
class NoticeOutbox extends Model
{
    protected $table = 'notice_outbox';

    protected $fillable = [
        'intake_message_id', 'application_id', 'channel', 'notice_version', 'idempotency_key', 'status',
        'attempts', 'sent_at', 'last_error',
    ];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime'];
    }
}
