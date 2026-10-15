<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Factory business setting (M11-P0-03). Never a secret.
 *
 * @property int $id
 * @property string $key
 * @property mixed $value_json
 * @property int $version
 * @property int|null $updated_by
 */
class Setting extends Model
{
    protected $fillable = ['key', 'value_json', 'version', 'updated_by'];

    protected function casts(): array
    {
        return ['value_json' => 'json'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
