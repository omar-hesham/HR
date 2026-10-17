<?php

namespace App\Domain\Intake;

/**
 * Result of one driver fetch: raw messages after the cursor/start pointer, plus the
 * IMAP UIDVALIDITY of the folder for cursor validation (M1-P0-01).
 */
final class InboundBatch
{
    /**
     * @param  list<RawInboundMessage>  $messages
     */
    public function __construct(
        public readonly array $messages,
        public readonly ?string $uidvalidity = null,
    ) {}
}
