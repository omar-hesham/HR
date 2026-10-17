<?php

namespace App\Domain\Intake\Contracts;

use App\Domain\Intake\InboundBatch;
use App\Models\Mailbox;
use Illuminate\Support\Carbon;

/**
 * Contract every mail driver implements (M1-P0-04): fetch RAW messages only.
 * Drivers never parse, never modify the source mailbox and never advance the
 * cursor themselves.
 */
interface MailboxDriver
{
    /**
     * Raw messages after the cursor (exclusive) and received at/after $startAt,
     * oldest first, at most $limit.
     */
    public function fetch(Mailbox $mailbox, ?string $cursor, ?Carbon $startAt, int $limit): InboundBatch;
}
