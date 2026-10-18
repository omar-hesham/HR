<?php

namespace App\Infrastructure\Intake;

use App\Domain\Intake\Contracts\MailboxDriver;
use App\Domain\Intake\InboundBatch;
use App\Domain\Intake\RawInboundMessage;
use App\Models\Mailbox;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Connection\Protocols\ImapProtocol;

/**
 * IMAP driver on webklex/php-imap for the three approved setups (M1-P0-04):
 * hosting password IMAP over TLS, Microsoft 365 XOAUTH2 (client credentials)
 * and Google Workspace XOAUTH2 (refresh token). Read-only behaviour: folders
 * are opened with EXAMINE and fetched with BODY.PEEK (leaveUnread); this
 * driver never moves, deletes or flags source messages (spec D8 warning).
 */
final class ImapMailboxDriver implements MailboxDriver
{
    public function __construct(
        private readonly OAuthAccessTokenProvider $tokens,
        private readonly bool $oauth = false,
        private readonly ?ClientManager $clients = null,
    ) {}

    public function testConnection(Mailbox $mailbox): void
    {
        $client = $this->connect($mailbox);
        try {
            $connection = $client->getConnection();
            if (! $connection instanceof ImapProtocol) {
                throw new \RuntimeException('native_imap_required');
            }
            $connection->examineFolder($mailbox->folder ?? (string) config('recruitment.intake.folder'))->validatedData();
        } finally {
            $client->disconnect();
        }
    }

    public function fetch(Mailbox $mailbox, ?string $cursor, ?Carbon $startAt, int $limit): InboundBatch
    {
        $client = $this->connect($mailbox);

        try {
            $connection = $client->getConnection();
            if (! $connection instanceof ImapProtocol) {
                throw new \RuntimeException('native_imap_required');
            }

            $selected = $connection->examineFolder($mailbox->folder ?? (string) config('recruitment.intake.folder'))->validatedData();
            $validity = isset($selected['uidvalidity']) ? (string) $selected['uidvalidity'] : null;
            $cursorUid = $validity !== $mailbox->uidvalidity ? 0 : (int) $cursor;
            $criteria = $startAt === null ? ['ALL'] : ['SINCE', $startAt->format('d-M-Y')];
            $uids = $connection->search($criteria)->validatedData();
            if (! is_array($uids)) {
                throw new \RuntimeException('invalid_imap_search');
            }
            sort($uids, SORT_NUMERIC);
            $raw = [];

            foreach ($uids as $uid) {
                $uid = (int) $uid;
                if ($uid <= $cursorUid) {
                    continue;
                }
                // The protocol returns the original RFC 5322 literal, without MIME parsing.
                $fetched = $connection->fetch(['BODY.PEEK[]', 'INTERNALDATE'], $uid)->validatedData();
                if (! is_array($fetched) || ! is_string($fetched['BODY[]'] ?? null) || ! is_string($fetched['INTERNALDATE'] ?? null)) {
                    throw new \RuntimeException('invalid_imap_message');
                }
                $receivedAt = Carbon::parse($fetched['INTERNALDATE']);
                if ($startAt !== null && $receivedAt->lt($startAt)) {
                    continue;
                }
                $raw[] = new RawInboundMessage((string) $uid, $fetched['BODY[]'], $receivedAt);
                if (count($raw) >= $limit) {
                    break;
                }
            }

            return new InboundBatch($raw, $validity);
        } finally {
            try {
                $client->disconnect();
            } catch (\Throwable $exception) {
                Log::debug('IMAP disconnect failed.', ['exception' => $exception::class]);
            }
        }
    }

    private function connect(Mailbox $mailbox): Client
    {
        $credentials = MailboxCredentialSet::forMailbox($mailbox);

        $password = $this->oauth
            ? $this->accessToken($mailbox, $credentials)
            : $credentials->password();

        if (! in_array($mailbox->encryption ?? 'ssl', ['ssl', 'tls'], true)) {
            throw new \RuntimeException('imap_tls_required');
        }

        $client = ($this->clients ?? new ClientManager([]))->make([
            'host' => (string) $mailbox->host,
            'port' => (int) ($mailbox->port ?? 993),
            'encryption' => $mailbox->encryption !== null && $mailbox->encryption !== '' ? $mailbox->encryption : 'ssl',
            'validate_cert' => true,
            'protocol' => 'imap',
            'username' => $credentials->username() !== '' ? $credentials->username() : $mailbox->address,
            'password' => $password,
            'authentication' => $this->oauth ? 'oauth' : null,
            'timeout' => 30,
        ]);

        $client->connect();

        return $client;
    }

    private function accessToken(Mailbox $mailbox, MailboxCredentialSet $credentials): string
    {
        return $mailbox->provider === 'm365'
            ? $this->tokens->microsoft365($credentials)
            : $this->tokens->google($credentials);
    }
}
