<?php

namespace App\Models;

use App\Casts\EncryptedContent;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $extraction_id
 * @property int|null $ai_run_id
 * @property string|null $mask_map_enc */
class AiExtractionContext extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['mask_map_enc' => EncryptedContent::class];
    }
}
