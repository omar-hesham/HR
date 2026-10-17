<?php

namespace App\Jobs;

use App\Models\IntakeMessage;
use App\Models\Mailbox;
use App\Services\Intake\MailboxDriverFactory;
use App\Services\Intake\ProcessInboundMessage;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Polls one mailbox (M1-P0-01, spec §9.4 PollMailbox): queue "intake", unique and
 * overlap-locked per mailbox, 3 attempts. The cursor advances only past messages
 * that were ingested (or safely skipped); a message that throws blocks the cursor
 * so the next poll retries it — hash/Message-ID dedupe makes retries harmless.
 */
final class PollMailbox implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 290;

    public int $uniqueFor = 1800;

    public function __construct(public readonly int $mailboxId)
    {
        $this->onQueue((string) config('recruitment.queues.intake', 'intake'));
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function uniqueId(): string
    {
        return (string) $this->mailboxId;
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('poll-mailbox-'.$this->mailboxId))->expireAfter(600)];
    }

    public function handle(MailboxDriverFactory $drivers, ProcessInboundMessage $processor): void
    {
        $mailbox = Mailbox::query()->find($this->mailboxId);

        if ($mailbox === null || ! $mailbox->active) {
            return;
        }

        if ($mailbox->start_at === null) {
            $mailbox->forceFill(['start_at' => $mailbox->created_at ?? now()])->save();
        }

        try {
            $batch = $drivers->for($mailbox)->fetch(
                $mailbox,
                $mailbox->cursor,
                $mailbox->start_at,
                (int) config('recruitment.intake.max_messages_per_poll'),
            );
        } catch (Throwable $e) {
            // T-M1-03: connection failures are visible in health and retried.
            $mailbox->forceFill(['last_error' => 'mailbox_fetch_failed'])->save();

            throw new \RuntimeException('mailbox_fetch_failed');
        }

        /** @var array<string, mixed> $update */
        $update = [];
        $uidvalidityChanged = false;

        if ($batch->uidvalidity !== null && $mailbox->uidvalidity !== null && $batch->uidvalidity !== $mailbox->uidvalidity) {
            // UIDs invalidated: re-import from start; dedupe prevents duplicates (D-102).
            $uidvalidityChanged = true;
            $update['cursor'] = null;
            $update['uidvalidity'] = $batch->uidvalidity;
        } elseif ($batch->uidvalidity !== null) {
            $update['uidvalidity'] = $batch->uidvalidity;
        }

        $cursor = null;
        $gap = false;
        $latestReceivedAt = null;

        foreach ($batch->messages as $rawMessage) {
            try {
                $externalId = $batch->uidvalidity === null ? $rawMessage->externalId : $batch->uidvalidity.':'.$rawMessage->externalId;
                $result = Cache::lock('mail-intake', 600)->block(5,
                    fn () => $processor->process($mailbox, $externalId, $rawMessage->raw, $rawMessage->receivedAt));

                $cursor = $rawMessage->externalId;

                $receivedAt = $rawMessage->receivedAt ?? $result->receivedAt;
                if ($receivedAt !== null && ($latestReceivedAt === null || $receivedAt->gt($latestReceivedAt))) {
                    $latestReceivedAt = $receivedAt;
                }
            } catch (Throwable $e) {
                // Stop at the gap so this receipt is retried before later messages.
                $gap = true;
                $update['last_error'] = 'intake_processing_failed';
                IntakeMessage::query()->where('mailbox_id', $mailbox->id)->where('external_id', $externalId)
                    ->update(['last_error' => 'intake_processing_failed']);
                break;
            }
        }

        if ($cursor !== null) {
            $update['cursor'] = $cursor;
        }

        if ($latestReceivedAt !== null && ($mailbox->last_message_at === null || $latestReceivedAt->gt($mailbox->last_message_at))) {
            $update['last_message_at'] = $latestReceivedAt;
        }

        $update['last_error'] = match (true) {
            $gap => $update['last_error'] ?? 'intake_error',
            $uidvalidityChanged => 'uidvalidity_changed',
            default => null,
        };

        if (! $gap) {
            $update['last_success_at'] = now();
        }
        $mailbox->forceFill($update)->save();

        if ($gap) {
            throw new \RuntimeException('intake_processing_failed');
        }
    }

    public function failed(?Throwable $exception): void
    {
        $mailbox = Mailbox::query()->find($this->mailboxId);

        if ($mailbox !== null && $exception !== null) {
            $mailbox->forceFill(['last_error' => 'mailbox_poll_failed'])->save();
        }
    }
}
