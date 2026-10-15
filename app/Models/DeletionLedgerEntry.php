<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Prevents resurrection of deleted data after a restore (PRIV-P0-03, Milestone 4).
 * Holds opaque ids only.
 *
 * @property int $id
 * @property list<string> $opaque_resource_ids
 * @property string|null $source_hmac
 * @property string $reason_code
 * @property Carbon $deleted_at
 */
class DeletionLedgerEntry extends Model
{
    protected $table = 'deletion_ledger';

    public $timestamps = false;

    protected $fillable = ['opaque_resource_ids', 'source_hmac', 'reason_code', 'deleted_at'];

    protected function casts(): array
    {
        return [
            'opaque_resource_ids' => 'array',
            'deleted_at' => 'datetime',
        ];
    }
}
