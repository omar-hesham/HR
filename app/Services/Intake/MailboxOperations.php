<?php

namespace App\Services\Intake;

use App\Jobs\PollMailbox;
use App\Models\Mailbox;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Throwable;

final readonly class MailboxOperations
{
    public function __construct(private MailboxDriverFactory $drivers) {}

    /** The boundary deliberately returns fixed codes, never vendor exceptions or credentials. */
    public function testConnection(User $actor, Mailbox $mailbox): string
    {
        Gate::forUser($actor)->authorize('update', $mailbox);
        try {
            $this->drivers->for($mailbox)->testConnection($mailbox);
            $code = 'connection_ok';
        } catch (Throwable) {
            $code = 'connection_failed';
        }
        activity('operations')->causedBy($actor)->performedOn($mailbox)->event('mailbox.connection_test')
            ->withProperties(['code' => $code])->log('mailbox.connection_test');

        return $code;
    }

    public function poll(User $actor, Mailbox $mailbox): void
    {
        Gate::forUser($actor)->authorize('update', $mailbox);
        abort_unless($mailbox->active, 422);
        PollMailbox::dispatch($mailbox->id);
        activity('operations')->causedBy($actor)->performedOn($mailbox)->event('mailbox.poll_requested')->log('mailbox.poll_requested');
    }
}
