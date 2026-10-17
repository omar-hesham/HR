<?php

namespace App\Domain\Ai;

use Opis\JsonSchema\Validator;
use Throwable;

final readonly class Schema
{
    public const VERSION = '1.0';

    public function json(): string
    {
        return (string) file_get_contents(resource_path('ai/extraction-1.0.schema.json'));
    }

    /**
     * @param array<string, mixed> $payload */
    public function validate(array $payload): void
    {
        try {
            $valid = (new Validator)->validate(json_decode(json_encode($payload, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR), json_decode($this->json(), false, 512, JSON_THROW_ON_ERROR))->isValid();
        } catch (Throwable) {
            $valid = false;
        }
        if (! $valid) {
            throw new AiException(AiError::InvalidSchema);
        }
    }
}
