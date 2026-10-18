<?php

namespace App\Infrastructure\Intake;

use App\Domain\Intake\Contracts\MailboxDriver;
use App\Domain\Intake\InboundBatch;
use App\Domain\Intake\RawInboundMessage;
use App\Models\Mailbox;
use Illuminate\Support\Carbon;

/**
 * Test / local-development driver: reads .eml files from a folder, oldest name
 * first. The cursor is the last processed file name; external_id is the file
 * name without extension. The folder path is taken from the mailbox's host
 * column, falling back to the configured default (D-101).
 */
final class FakeMailboxDriver implements MailboxDriver
{
    public function testConnection(Mailbox $mailbox): void
    {
        $directory = $mailbox->host ?: (string) config('recruitment.intake.fake_path');
        if (! is_dir($directory) || ! is_readable($directory)) {
            throw new \RuntimeException('fake_mailbox_unavailable');
        }
    }

    public function fetch(Mailbox $mailbox, ?string $cursor, ?Carbon $startAt, int $limit): InboundBatch
    {
        $directory = trim((string) ($mailbox->host ?? ''));

        if ($directory === '') {
            $directory = (string) config('recruitment.intake.fake_path');
        }

        if (! is_dir($directory)) {
            return new InboundBatch([]);
        }

        $files = glob(rtrim($directory, '/\\').'/*.eml') ?: [];
        sort($files);

        /** @var list<RawInboundMessage> $messages */
        $messages = [];

        foreach ($files as $file) {
            $externalId = basename($file, '.eml');

            if ($cursor !== null && strcmp($externalId, $cursor) <= 0) {
                continue;
            }

            $raw = @file_get_contents($file);

            if ($raw === false) {
                throw new \RuntimeException('fake_message_read_failed');
            }

            if ($startAt !== null && $this->receivedBefore($raw, $startAt)) {
                continue;
            }

            $messages[] = new RawInboundMessage($externalId, $raw);

            if (count($messages) >= $limit) {
                break;
            }
        }

        return new InboundBatch($messages);
    }

    /**
     * Cheap Date-header peek without a full parse (D-112 start pointer).
     */
    private function receivedBefore(string $raw, Carbon $startAt): bool
    {
        if (preg_match('/^date:\s*(.+)$/im', $raw, $matches) !== 1) {
            return false;
        }

        try {
            return Carbon::parse(trim($matches[1]))->lt($startAt);
        } catch (\Throwable) {
            return false;
        }
    }
}
