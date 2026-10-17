<?php

namespace App\Domain\Intake;

use Illuminate\Support\Carbon;

/**
 * A raw, unparsed inbound message as returned by a driver (kickoff §5.2: raw first).
 */
final class RawInboundMessage
{
    public function __construct(
        public readonly string $externalId,
        public readonly string $raw,
        public readonly ?Carbon $receivedAt = null,
    ) {}
}
