<?php

namespace App\Services\Ai;

use App\Domain\Ai\EvidenceVerifier;
use App\Domain\Ai\Masker;
use App\Domain\Text\PhoneNormalizer;

final readonly class DeterministicIdentity
{
    public function __construct(private Masker $masker, private PhoneNormalizer $phones) {}

    /**
     * @return array<string,mixed> */
    public function extract(string $text, int $documentId, ?string $sender = null, ?string $filename = null): array
    {
        $identity = $this->masker->identity($text, $sender, $filename);
        preg_match('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/u', $text, $email);
        preg_match('/(?<![\p{L}\p{N}])(?:\+|00|0)[\p{N} ()\-.]{7,}[\p{N}](?![\p{N}])/u', $text, $phone);
        $normalized = isset($phone[0]) ? $this->phones->normalize($phone[0]) : null;
        $fact = static fn ($value, $quote) => ['value' => $value, 'confidence' => $value === null ? 0 : 1, 'evidence' => $quote === null ? [] : [['quote' => $quote]]];
        $name = $identity['name'];
        $nameQuote = $name !== null && mb_strpos($text, $name) !== false ? $name : null;
        $payload = ['name' => $fact($nameQuote === null ? null : $name, $nameQuote), 'contacts' => ['email' => $fact($email[0] ?? null, $email[0] ?? null), 'phone' => $fact($normalized?->e164, $normalized?->e164 === null ? null : $phone[0])]];

        return (new EvidenceVerifier)->verify($payload, $text, $documentId) + ['display_name' => $name, 'phone_original' => $normalized?->original];
    }
}
