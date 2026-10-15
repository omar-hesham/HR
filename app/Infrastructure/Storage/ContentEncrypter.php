<?php

namespace App\Infrastructure\Storage;

use Illuminate\Encryption\Encrypter;
use RuntimeException;

/**
 * Application-level encryption for CV files and CV-derived text (SEC-P0-02, D-37).
 * AES-256-GCM (authenticated) with FILE_ENCRYPTION_KEY, separate from APP_KEY.
 */
final class ContentEncrypter
{
    private readonly Encrypter $encrypter;

    public function __construct(string $key)
    {
        if (str_starts_with($key, 'base64:')) {
            $key = (string) base64_decode(substr($key, 7), true);
        }

        if (strlen($key) !== 32) {
            throw new RuntimeException('FILE_ENCRYPTION_KEY must be a 32-byte key (base64: prefixed).');
        }

        $this->encrypter = new Encrypter($key, 'aes-256-gcm');
    }

    public static function generateKey(): string
    {
        return 'base64:'.base64_encode(Encrypter::generateKey('aes-256-gcm'));
    }

    public function encryptString(string $plain): string
    {
        return $this->encrypter->encryptString($plain);
    }

    public function decryptString(string $payload): string
    {
        return $this->encrypter->decryptString($payload);
    }
}
