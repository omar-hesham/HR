<?php

namespace App\Models;

use App\Casts\EncryptedContent;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $path
 * @property string $after_enc
 * @property string $source */
class FieldCorrection extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['before_enc' => EncryptedContent::class, 'after_enc' => EncryptedContent::class, 'reason_enc' => 'encrypted'];
    }
}
