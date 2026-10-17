<?php

namespace App\Domain\Intake;

use RuntimeException;

/**
 * The raw bytes are not a parseable RFC 5322 message (M1-P0-03: visible, not dropped).
 */
final class MessageParseFailure extends RuntimeException
{
    public static function malformed(): self
    {
        return new self('The raw message could not be parsed.');
    }
}
