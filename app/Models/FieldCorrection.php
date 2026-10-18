<?php

namespace App\Models;

use App\Casts\EncryptedContent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $path
 * @property string $after_enc
 * @property string $source
 * @property int $user_id
 */
class FieldCorrection extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['before_enc' => EncryptedContent::class, 'after_enc' => EncryptedContent::class, 'reason_enc' => 'encrypted'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
