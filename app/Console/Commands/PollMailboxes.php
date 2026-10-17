<?php

namespace App\Console\Commands;

use App\Jobs\PollMailbox;
use App\Jobs\SendPrivacyNotice;
use App\Models\Mailbox;
use App\Models\NoticeOutbox;
use Illuminate\Console\Command;

/**
 * Scheduler entry (M1-P0-01): dispatches one PollMailbox job per active mailbox
 * every 5 minutes. The jobs carry the overlap lock and cursor logic.
 */
final class PollMailboxes extends Command
{
    protected $signature = 'recruit:poll-mailboxes';

    public function getDescription(): string
    {
        return __('app.intake.poll_command_description');
    }

    public function handle(): int
    {
        $ids = Mailbox::query()->where('active', true)->pluck('id');

        foreach ($ids as $id) {
            PollMailbox::dispatch($id);
        }

        foreach (NoticeOutbox::query()->where('status', 'pending')->pluck('id') as $noticeId) {
            SendPrivacyNotice::dispatch($noticeId);
        }

        $this->info(__('app.intake.poll_dispatched', ['count' => $ids->count()]));

        return self::SUCCESS;
    }
}
