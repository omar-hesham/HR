<?php

namespace App\Models;

use App\Casts\EncryptedContent;
use Illuminate\Database\Eloquent\Model;

/** @property int $id */
class DuplicateResolution extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['reason_enc' => 'encrypted', 'links_enc' => EncryptedContent::class];
    }

    protected static function booted(): void
    {
        static::updating(fn (self $row) => throw Exceptions\ImmutableRecordException::forModel($row));
    }
}
