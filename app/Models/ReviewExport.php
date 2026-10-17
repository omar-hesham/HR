<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property int $user_id
 * @property int $recruitment_job_id
 * @property string $format
 * @property string $path
 * @property bool $identified
 * @property list<int> $application_ids
 * @property string $purpose_enc
 * @property Carbon $expires_at
 * @property Carbon|null $purged_at
 */
class ReviewExport extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['identified' => 'boolean', 'application_ids' => 'array', 'purpose_enc' => 'encrypted', 'expires_at' => 'datetime', 'purged_at' => 'datetime'];
    }
}
