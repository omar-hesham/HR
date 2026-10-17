<?php

namespace App\Domain\Intake\Contracts;

use App\Domain\Intake\MessageParseFailure;
use App\Domain\Intake\ParsedInboundMessage;

/**
 * Parses a raw RFC 5322 message into envelope + attachments.
 */
interface MessageParser
{
    /**
     * @throws MessageParseFailure When the raw bytes are not a parseable message.
     */
    public function parse(string $raw): ParsedInboundMessage;
}
