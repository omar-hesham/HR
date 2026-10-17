<?php

namespace App\Services\Intake;

use App\Domain\Intake\Contracts\MailboxDriver;
use App\Infrastructure\Intake\FakeMailboxDriver;
use App\Infrastructure\Intake\ImapMailboxDriver;
use App\Infrastructure\Intake\OAuthAccessTokenProvider;
use App\Models\Mailbox;
use InvalidArgumentException;
use Webklex\PHPIMAP\ClientManager;

/**
 * Maps a mailbox provider to its driver (M1-P0-04, D-101). Only the fake
 * driver is exercised by tests and local development.
 */
final class MailboxDriverFactory
{
    public function __construct(private readonly OAuthAccessTokenProvider $tokens, private readonly ClientManager $clients) {}

    public function for(Mailbox $mailbox): MailboxDriver
    {
        return match ($mailbox->provider) {
            'fake' => app(FakeMailboxDriver::class),
            'imap' => new ImapMailboxDriver($this->tokens, oauth: false, clients: $this->clients),
            'm365' => new ImapMailboxDriver($this->tokens, oauth: true, clients: $this->clients),
            'google' => new ImapMailboxDriver($this->tokens, oauth: true, clients: $this->clients),
            default => throw new InvalidArgumentException("Unknown mailbox provider [{$mailbox->provider}]."),
        };
    }
}
