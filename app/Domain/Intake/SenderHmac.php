<?php

namespace App\Domain\Intake;

use RuntimeException;

/**
 * Pseudonymous sender identity for de-duplication and notice windows (D-115).
 * HMAC-SHA256 with DEDUP_HMAC_KEY; the address itself is stored encrypted.
 */
final class SenderHmac
{
    private readonly string $key;

    public function __construct(string $key)
    {
        if ($key === '') {
            throw new RuntimeException('DEDUP_HMAC_KEY must be set (php artisan recruit:key).');
        }

        $this->key = $key;
    }

    public function hash(?string $address): ?string
    {
        if ($address === null || trim($address) === '') {
            return null;
        }

        return hash_hmac('sha256', strtolower(trim($address)), $this->key);
    }
}
