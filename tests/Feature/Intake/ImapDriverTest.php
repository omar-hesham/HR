<?php

use App\Infrastructure\Intake\ImapMailboxDriver;
use App\Infrastructure\Intake\MailboxCredentialSet;
use App\Infrastructure\Intake\OAuthAccessTokenProvider;
use App\Models\IntakeMessage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Connection\Protocols\ImapProtocol;
use Webklex\PHPIMAP\Connection\Protocols\Response;

function imapResponse(mixed $contents): Response
{
    return (new Response(1))->setResult($contents)->setCanBeEmpty(true);
}

it('fetches original bytes without MIME parsing or changing flags using each credential setup', function (string $provider) {
    $credentials = ['username' => 'synthetic@example.test', 'password' => 'synthetic-password', 'tenant_id' => 'synthetic-tenant', 'client_id' => 'synthetic-client', 'client_secret' => 'synthetic-secret', 'refresh_token' => 'synthetic-refresh'];
    $mailbox = fakeMailbox(['provider' => $provider, 'credentials' => $credentials, 'uidvalidity' => '100', 'cursor' => '900', 'encryption' => 'ssl']);
    $stored = DB::table('mailboxes')->where('id', $mailbox->id)->value('credentials');
    expect($stored)->not->toContain('synthetic-password')->not->toContain('synthetic-secret')
        ->and($mailbox->fresh()->credentials)->toBe($credentials)
        ->and($mailbox->toArray())->not->toHaveKey('credentials');

    Http::fake(['https://login.microsoftonline.com/*' => Http::response(['access_token' => 'synthetic-access-token']), 'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'synthetic-access-token'])]);
    $protocol = Mockery::mock(ImapProtocol::class);
    $protocol->shouldReceive('examineFolder')->with('INBOX')->andReturn(imapResponse(['uidvalidity' => 200]));
    $protocol->shouldReceive('search')->andReturn(imapResponse([2]));
    // An intentionally malformed message must reach raw storage unchanged.
    $original = "not MIME\r\n\x00raw bytes";
    $protocol->shouldReceive('fetch')->with(['BODY.PEEK[]', 'INTERNALDATE'], 2)->andReturn(imapResponse(['BODY[]' => $original, 'INTERNALDATE' => '01-Oct-2026 12:00:00 +0000']));
    $client = Mockery::mock(Client::class);
    $client->shouldReceive('connect', 'disconnect')->andReturnSelf();
    $client->shouldReceive('getConnection')->andReturn($protocol);
    $clients = Mockery::mock(ClientManager::class);
    $options = null;
    $clients->shouldReceive('make')->andReturnUsing(function ($configuration) use ($client, &$options) {
        $options = $configuration;

        return $client;
    });
    $driver = new ImapMailboxDriver(new OAuthAccessTokenProvider, oauth: $provider !== 'imap', clients: $clients);
    $batch = $driver->fetch($mailbox, '900', Carbon::parse('2026-10-01'), 10);
    expect($batch->uidvalidity)->toBe('200')->and($batch->messages)->toHaveCount(1)
        ->and($batch->messages[0]->raw)->toBe($original)
        ->and($batch->messages[0]->externalId)->toBe('2')
        ->and($batch->messages[0]->receivedAt->toIso8601String())->toBe('2026-10-01T12:00:00+00:00')
        ->and($options['validate_cert'])->toBeTrue()
        ->and($options['encryption'])->toBe('ssl')
        ->and($options['username'])->toBe($credentials['username'])
        ->and($options['password'])->toBe($provider === 'imap' ? 'synthetic-password' : 'synthetic-access-token');
    if ($provider === 'imap') {
        Http::assertNothingSent();
    } else {
        Http::assertSentCount(1);
    }
})->with(['imap', 'm365', 'google']);

it('resolves environment-backed references through cached configuration', function () {
    config(['recruitment.intake.credentials.default.password' => 'synthetic-config-password']);
    $credentials = MailboxCredentialSet::forMailbox(fakeMailbox());
    expect($credentials->password())->toBe('synthetic-config-password');
});

it('does not reuse a stale UID from a previous UIDVALIDITY epoch', function () {
    Storage::fake('private');
    Queue::fake();
    $mailbox = fakeMailbox(['uidvalidity' => '100', 'cursor' => '900']);
    IntakeMessage::query()->create(['mailbox_id' => $mailbox->id, 'external_id' => '100:2', 'received_at' => now(), 'outcome' => 'processed']);
    $raw = file_get_contents(emlFixture('02-english-cv'));
    $mailbox->forceFill(['provider' => 'imap', 'encryption' => 'ssl'])->save();
    $protocol = Mockery::mock(ImapProtocol::class);
    $protocol->shouldReceive('examineFolder')->andReturn(imapResponse(['uidvalidity' => 200]));
    $protocol->shouldReceive('search')->andReturn(imapResponse([2]));
    $protocol->shouldReceive('fetch')->andReturn(imapResponse(['BODY[]' => $raw, 'INTERNALDATE' => '01-Oct-2026 12:00:00 +0000']));
    $client = Mockery::mock(Client::class);
    $client->shouldReceive('connect', 'disconnect')->andReturnSelf();
    $client->shouldReceive('getConnection')->andReturn($protocol);
    $clients = Mockery::mock(ClientManager::class);
    $clients->shouldReceive('make')->andReturn($client);
    app()->instance(ClientManager::class, $clients);
    pollMailbox($mailbox);
    expect($mailbox->refresh()->uidvalidity)->toBe('200')->and($mailbox->cursor)->toBe('2')
        ->and($mailbox->last_error)->toBe('uidvalidity_changed')
        ->and($mailbox->last_message_at->toIso8601String())->toBe('2026-10-01T12:00:00+00:00')
        ->and(IntakeMessage::query()->where('external_id', '200:2')->sole()->outcome)->toBe('processed');
});

it('does not expose OAuth endpoint errors or credentials in poll failures', function () {
    $mailbox = fakeMailbox(['provider' => 'm365', 'credentials' => ['tenant_id' => 'synthetic', 'client_id' => 'synthetic-client', 'client_secret' => 'private-synthetic-secret']]);
    Http::fake(['*' => function () {
        throw new RuntimeException('private-synthetic-secret');
    }]);
    try {
        pollMailbox($mailbox);
        test()->fail('Expected a safe mailbox failure.');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('mailbox_fetch_failed')->and($exception->getPrevious())->toBeNull();
    }
    expect($mailbox->refresh()->last_error)->toBe('mailbox_fetch_failed');
});
