<?php

/**
 * Intake health page (M11-P0-01, brief §7) and the no-mail alert.
 */

use App\Domain\Access\Role;
use App\Jobs\MonitorMailboxSilence;
use App\Services\Intake\IntakeHealthSnapshot;
use App\Services\Settings\SettingsRepository;
use Illuminate\Support\Facades\Mail;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    Storage::fake('private');
});

it('shows intake health to roles with ops.health.view only', function (Role $role, int $status) {
    $user = userWithRole($role);

    $response = $this->actingAs($user)->get('/admin/intake-health');

    expect($response->status())->toBe($status);
})->with([
    'owner' => [Role::Owner, 200],
    'coordinator' => [Role::Coordinator, 200],
    'operator' => [Role::Operator, 200],
    'recruiter (denied)' => [Role::Recruiter, 403],
    'hiring manager (denied)' => [Role::HiringManager, 403],
    'management (denied)' => [Role::Management, 403],
]);

it('lists mailboxes with last poll, per-outcome counts and errors', function () {
    $mailbox = fakeMailbox(['address' => 'hr-mailbox@example.test']);
    pushEml($mailbox, '01-arabic-cv');
    pushEml($mailbox, '08-no-attachments');
    pollMailbox($mailbox);

    $html = $this->actingAs(userWithRole(Role::Owner))->get('/admin/intake-health')->getContent();

    expect($html)->toContain('hr-mailbox@example.test')
        ->and($html)->toContain(__('enums.intake_outcome.processed'))
        ->and($html)->toContain(__('enums.intake_outcome.ignored_with_reason'));
});

it('alerts once per silence period and keeps quiet for healthy mailboxes', function () {
    Mail::fake();
    config(['recruitment.intake.health_alert_recipient' => 'ops@example.test']);

    $silent = fakeMailbox(['last_message_at' => now()->subDays(3), 'start_at' => now()->subDays(10)]);
    $healthy = fakeMailbox(['last_message_at' => now()->subHour(), 'start_at' => now()->subDays(10)]);

    (new MonitorMailboxSilence)->handle(app(SettingsRepository::class));
    (new MonitorMailboxSilence)->handle(app(SettingsRepository::class));

    expect(Activity::query()->where('event', 'intake.silence_alert')->count())->toBe(1)
        ->and($silent->refresh()->last_silence_alert_at)->not->toBeNull()
        ->and($healthy->refresh()->last_silence_alert_at)->toBeNull();

    Mail::assertSentCount(1);
});

it('uses the per-mailbox threshold, falling back to settings', function () {
    config(['recruitment.intake.health_alert_recipient' => '']);

    $settings = app(SettingsRepository::class);
    $settings->update(['intake.silence_alert_hours' => 24], null);

    $below = fakeMailbox(['last_message_at' => now()->subHours(2), 'start_at' => now()->subDays(10)]);
    $override = fakeMailbox(['silence_alert_hours' => 1, 'last_message_at' => now()->subHours(2), 'start_at' => now()->subDays(10)]);

    (new MonitorMailboxSilence)->handle($settings);

    expect($below->refresh()->last_silence_alert_at)->toBeNull()
        ->and($override->refresh()->last_silence_alert_at)->not->toBeNull();
});

it('counts quarantined files on the health snapshot', function () {
    $snapshot = app(IntakeHealthSnapshot::class)->build();

    expect($snapshot)->toHaveKeys(['mailboxes', 'totals', 'notices', 'quarantined', 'errors'])
        ->and($snapshot['totals'])->toHaveKeys(['processed', 'ignored', 'needs_review', 'pending', 'total'])
        ->and($snapshot['quarantined'])->toBe(0);
});

it('keeps failed operational-alert delivery retryable without exposing transport secrets', function () {
    config(['recruitment.intake.health_alert_recipient' => 'ops@example.test']);
    $mailbox = fakeMailbox(['last_message_at' => now()->subDays(3)]);
    Mail::shouldReceive('mailer')->andThrow(new RuntimeException('synthetic-smtp-secret'));
    try {
        (new MonitorMailboxSilence)->handle(app(SettingsRepository::class));
        test()->fail('An operational alert failure must stay visible.');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('health_alert_delivery_failed')
            ->and($exception->getPrevious())->toBeNull();
    }
    expect($mailbox->refresh()->last_error)->toBe('health_alert_delivery_failed')
        ->and($mailbox->last_silence_alert_at)->toBeNull();
});

it('keeps checking the other mailboxes when one alert fails to send, then reports the failure', function () {
    config(['recruitment.intake.health_alert_recipient' => 'ops@example.test']);

    $first = fakeMailbox(['last_message_at' => now()->subDays(3), 'start_at' => now()->subDays(10)]);
    $second = fakeMailbox(['last_message_at' => now()->subDays(3), 'start_at' => now()->subDays(10)]);

    $sent = 0;
    $mailer = new class($sent)
    {
        public function __construct(private int &$sent) {}

        public function send(mixed $mailable): void
        {
            if (++$this->sent === 1) {
                throw new RuntimeException('smtp down');
            }
        }
    };
    Mail::shouldReceive('mailer')->andReturn($mailer);

    expect(fn () => (new MonitorMailboxSilence)->handle(app(SettingsRepository::class)))
        ->toThrow(RuntimeException::class, 'health_alert_delivery_failed');

    $alerted = collect([$first->refresh(), $second->refresh()])->filter(fn ($m) => $m->last_silence_alert_at !== null);

    expect($sent)->toBe(2)
        ->and($alerted)->toHaveCount(1);
});
