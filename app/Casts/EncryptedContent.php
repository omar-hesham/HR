<?php

namespace App\Casts;

use App\Infrastructure\Storage\ContentEncrypter;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Encrypts CV-derived text with the content key (not APP_KEY).
 *
 * @implements CastsAttributes<string|null, string|null>
 */
final class EncryptedContent implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return app(ContentEncrypter::class)->decryptString((string) $value);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        return app(ContentEncrypter::class)->encryptString((string) $value);
    }
}
