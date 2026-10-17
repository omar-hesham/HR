<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Nightly backup record (M11-P0-02, Milestone 4).
 *
 * @property int $id
 * @property string $status
 */
class BackupRun extends Model
{
    protected $fillable = ['started_at', 'finished_at', 'status', 'manifest_hash', 'location_ref', 'restore_test_at', 'restore_engine'];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'restore_test_at' => 'datetime',
        ];
    }
}
