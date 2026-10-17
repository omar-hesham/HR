<?php

namespace App\Domain\Ai;

final class VerificationSummary
{
    /** @param array<mixed> $payload */
    public function firstError(array $payload): ?string
    {
        if (isset($payload['verification_error'])) {
            return $payload['verification_error'];
        }
        if (array_key_exists('value', $payload) && $payload['value'] !== null && ($payload['confidence'] ?? 0) < .8) {
            return 'LowConfidence';
        }
        foreach ($payload as $child) {
            if (is_array($child) && ($error = $this->firstError($child)) !== null) {
                return $error;
            }
        }

        return null;
    }
}
