<?php

namespace App\Domain\Intake;

use Illuminate\Support\Carbon;

/**
 * Envelope + structure of a parsed message. The raw bytes are stored before this
 * object ever exists (kickoff §5.2).
 */
final class ParsedInboundMessage
{
    /**
     * @param  array<string, string>  $headers  Lower-cased header names => raw values.
     * @param  list<InboundAttachment>  $attachments
     */
    public function __construct(
        public readonly ?string $messageIdHeader,
        public readonly ?string $subject,
        public readonly ?string $fromAddress,
        public readonly Carbon $receivedAt,
        public readonly array $headers,
        public readonly array $attachments,
        public readonly string $textBody,
    ) {}

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }
}
