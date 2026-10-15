<?php

namespace App\Domain\Text;

final readonly class PhoneNumberResult
{
    public const STATUS_EGYPT = 'egypt';

    public const STATUS_INTERNATIONAL = 'international';

    public const STATUS_INVALID = 'invalid';

    public const STATUS_AMBIGUOUS = 'ambiguous';

    public function __construct(
        public string $original,
        public ?string $e164,
        public string $status,
    ) {}

    public function isValid(): bool
    {
        return $this->e164 !== null;
    }
}
