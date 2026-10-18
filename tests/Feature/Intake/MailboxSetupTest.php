<?php

use App\Actions\Intake\SaveMailbox;
use App\Domain\Access\Permission;
use App\Domain\Access\Role;
use App\Filament\Resources\Mailboxes\MailboxResource;
use App\Filament\Resources\Mailboxes\Pages\CreateMailbox;
use App\Filament\Resources\Mailboxes\Pages\EditMailbox;
use App\Filament\Resources\Mailboxes\Pages\ListMailboxes;
use App\Jobs\PollMailbox;
use App\Models\Mailbox;
use App\Services\Intake\MailboxOperations;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Connection\Protocols\ImapProtocol;
use Webklex\PHPIMAP\Connection\Protocols\Response;

function mailboxSetupInput(string $provider = 'imap'): array
{
    return ['display_name' => 'Synthetic recruitment', 'address' => 'recruitment@example.test', 'provider' => $provider,
        'host' => 'imap.example.test', 'port' => 993, 'encryption' => 'ssl', 'folder' => 'Recruitment',
        'start_now' => true, 'active' => true, 'silence_alert_hours' => 24,
        'credentials_input' => ['username' => 'synthetic-user', 'password' => 'synthetic-password',
            'tenant_id' => 'synthetic-tenant', 'client_id' => 'synthetic-client', 'client_secret' => 'synthetic-secret', 'refresh_token' => 'synthetic-refresh']];
}

it('limits mailbox pages and actions to owner and operator even with a direct permission grant', function (Role $role) {
    $mailbox = Mailbox::factory()->create();
    $actor = userWithRole($role);
    $actor->givePermissionTo(Permission::MailboxesManage->value);
    $allowed = in_array($role, [Role::Owner, Role::Operator], true);
    foreach (['index', 'create', 'edit'] as $page) {
        $this->actingAs($actor)->get(MailboxResource::getUrl($page, ['record' => $mailbox]))->assertStatus($allowed ? 200 : 403);
    }
    if (! $allowed) {
        expect(fn () => app(MailboxOperations::class)->testConnection($actor, $mailbox))->toThrow(AuthorizationException::class);
        expect(fn () => app(MailboxOperations::class)->poll($actor, $mailbox))->toThrow(AuthorizationException::class);
        expect(fn () => app(SaveMailbox::class)->handle($actor, mailboxSetupInput()))->toThrow(AuthorizationException::class);
    }
})->with(Role::cases());

it('keeps MFA mandatory on mailbox setup', function () {
    $this->actingAs(userWithRole(Role::Owner, mfa: false))->get(MailboxResource::getUrl('index'))
        ->assertRedirect(route('filament.admin.auth.multi-factor-authentication.set-up-required'));
});

it('creates encrypted write-only credentials and preserves blank fields on edit', function (string $provider) {
    $actor = userWithRole(Role::Owner);
    $input = mailboxSetupInput($provider);
    Livewire::actingAs($actor)->test(CreateMailbox::class)->fillForm($input)->call('create')->assertHasNoFormErrors();
    $mailbox = Mailbox::query()->sole();
    $stored = DB::table('mailboxes')->value('credentials');
    expect($stored)->not->toContain('synthetic-password')->not->toContain('synthetic-secret')
        ->and($mailbox->toArray())->not->toHaveKey('credentials')
        ->and($mailbox->start_at->diffInSeconds(now(), true))->toBeLessThan(10);
    $before = $mailbox->credentials;
    $component = Livewire::actingAs($actor)->test(EditMailbox::class, ['record' => $mailbox->id]);
    foreach (array_values($before) as $secret) {
        $component->assertDontSee($secret);
    }
    expect($component->get('data.credentials_input'))->each->toBeNull();
    $component->fillForm(['display_name' => 'Updated synthetic', 'credentials_input' => []])->call('save')->assertHasNoFormErrors();
    expect($mailbox->fresh()->credentials)->toBe($before);
    $field = $provider === 'imap' ? 'password' : 'client_secret';
    $component->fillForm(['credentials_input' => [$field => 'synthetic-replacement']])->call('save')->assertHasNoFormErrors();
    expect($mailbox->fresh()->credentials[$field])->toBe('synthetic-replacement');
    $component->assertDontSee('synthetic-replacement');
    expect(DB::table('activity_log')->where('event', 'mailbox.saved')->get()->toJson())->not->toContain('synthetic-replacement');
})->with(['imap', 'm365', 'google']);

it('tests only authentication and EXAMINE of the configured folder for each provider', function (string $provider) {
    $mailbox = fakeMailbox(['provider' => $provider, 'folder' => 'Recruitment', 'encryption' => 'ssl',
        'credentials' => mailboxSetupInput()['credentials_input']]);
    Http::fake(['*' => Http::response(['access_token' => 'synthetic-access-token'])]);
    $protocol = Mockery::mock(ImapProtocol::class);
    $protocol->shouldReceive('examineFolder')->once()->with('Recruitment')->andReturn((new Response(1))->setResult(['uidvalidity' => '1']));
    $protocol->shouldNotReceive('fetch', 'search', 'selectFolder');
    $client = Mockery::mock(Client::class);
    $client->shouldReceive('connect', 'disconnect')->once()->andReturnSelf();
    $client->shouldReceive('getConnection')->andReturn($protocol);
    $clients = Mockery::mock(ClientManager::class);
    $clients->shouldReceive('make')->andReturn($client);
    app()->instance(ClientManager::class, $clients);
    $actor = userWithRole(Role::Operator);
    expect(app(MailboxOperations::class)->testConnection($actor, $mailbox))->toBe('connection_ok')
        ->and($mailbox->fresh()->cursor)->toBeNull()->and(DB::table('intake_messages')->count())->toBe(0);
})->with(['imap', 'm365', 'google']);

it('never leaks a vendor connection exception or secret through notifications or audit', function () {
    $mailbox = fakeMailbox(['provider' => 'imap', 'encryption' => 'ssl', 'credentials' => ['password' => 'PRIVATE-SYNTHETIC-SECRET']]);
    $clients = Mockery::mock(ClientManager::class);
    $clients->shouldReceive('make')->andThrow(new RuntimeException('PRIVATE-SYNTHETIC-SECRET raw provider error'));
    app()->instance(ClientManager::class, $clients);
    Livewire::actingAs(userWithRole(Role::Owner))->test(ListMailboxes::class)
        ->callTableAction('testConnection', $mailbox)->assertNotified(__('mailboxes.connection_failed'))
        ->assertDontSee('PRIVATE-SYNTHETIC-SECRET')->assertDontSee('raw provider error');
    expect(DB::table('activity_log')->where('event', 'mailbox.connection_test')->get()->toJson())
        ->not->toContain('PRIVATE-SYNTHETIC-SECRET')->toContain('connection_failed');
});

it('queues poll-now and denies an already-open component after access is revoked', function () {
    Queue::fake();
    $mailbox = fakeMailbox();
    $actor = userWithRole(Role::Operator);
    $component = Livewire::actingAs($actor)->test(ListMailboxes::class);
    $component->callTableAction('pollNow', $mailbox)->assertNotified(__('mailboxes.poll_queued'));
    Queue::assertPushed(PollMailbox::class, fn ($job) => $job->mailboxId === $mailbox->id);
    $actor->syncRoles([]);
    $component->call('$refresh')->assertForbidden();
    expect(fn () => app(MailboxOperations::class)->testConnection($actor, $mailbox))->toThrow(AuthorizationException::class);
});
