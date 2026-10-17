<?php

/**
 * PollMailbox job + scheduler entry (M1-P0-01, spec §9.4): overlap protection,
 * cursor advance, health fields, error recording, dispatch per active mailbox.
 */

use App\Jobs\MonitorMailboxSilence;
use App\Jobs\PollMailbox;
use App\Models\IntakeMessage;
use App\Models\Mailbox;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Storage::fake('private');
});

it('advances the cursor and records health after a successful poll', function () {
    $mailbox = fakeMailbox();
    pushEml($mailbox, '01-arabic-cv');
    pushEml($mailbox, '02-english-cv');

    pollMailbox($mailbox);

    $mailbox->refresh();

    expect($mailbox->cursor)->toBe('02-english-cv')
        ->and($mailbox->last_success_at)->not->toBeNull()
        ->and($mailbox->last_message_at?->toIso8601String())->toBe('2026-09-30T09:30:00+00:00')
        ->and($mailbox->last_error)->toBeNull()
        ->and(IntakeMessage::query()->count())->toBe(2);

    // A poll with no new mail still updates the last-success marker.
    $this->freezeTime();
    $this->travel(10)->minutes();
    pollMailbox($mailbox);

    expect($mailbox->refresh()->last_success_at->diffInMinutes(now()))->toBeLessThan(1)
        ->and(IntakeMessage::query()->count())->toBe(2);
});

it('has overlap protection and per-mailbox uniqueness', function () {
    $job = new PollMailbox(7);
    $middleware = $job->middleware();

    expect($job->uniqueId())->toBe('7')
        ->and($job->queue)->toBe('intake')
        ->and($job->tries)->toBe(3)
        ->and($job->backoff())->toBe([60, 300]);

    $overlap = false;

    foreach ($middleware as $layer) {
        if ($layer instanceof WithoutOverlapping && $layer->key === 'poll-mailbox-7') {
            $overlap = true;
        }
    }

    expect($overlap)->toBeTrue();
});

it('skips inactive mailboxes', function () {
    $mailbox = fakeMailbox(['active' => false]);
    pushEml($mailbox, '01-arabic-cv');

    pollMailbox($mailbox);

    expect(IntakeMessage::query()->count())->toBe(0)
        ->and($mailbox->refresh()->last_success_at)->toBeNull();
});

it('records driver failures on the mailbox and rethrows for retry (T-M1-03)', function () {
    $mailbox = fakeMailbox(['provider' => 'bogus']);

    try {
        pollMailbox($mailbox);
        $this->fail('The driver failure should have propagated.');
    } catch (RuntimeException) {
        // expected
    }

    expect($mailbox->refresh()->last_error)->toBe('mailbox_fetch_failed');
});

it('advances past an unreadable message only after preserving it for review', function () {
    $mailbox = fakeMailbox();

    pushEml($mailbox, '01-arabic-cv');
    pushEml($mailbox, '99-exploding', str_repeat("no header line here\r\n", 5));

    pollMailbox($mailbox);

    // The unparseable message is accounted for (parse_failed), not an exception,
    // so the cursor still advances — the error path stays visible on the row.
    $mailbox->refresh();

    expect(IntakeMessage::query()->where('external_id', '99-exploding')->sole()->outcome)
        ->toBe('needs_review')
        ->and($mailbox->cursor)->toBe('99-exploding')
        ->and(IntakeMessage::query()->where('external_id', '99-exploding')->sole()->raw_path)->not->toBeNull();
});

it('dispatches one poll job per active mailbox from the scheduler command', function () {
    Queue::fake();

    $one = fakeMailbox();
    $two = fakeMailbox();
    fakeMailbox(['active' => false]);

    $this->artisan('recruit:poll-mailboxes')->assertSuccessful();

    Queue::assertPushed(PollMailbox::class, 2);
    Queue::assertPushed(PollMailbox::class, static fn (PollMailbox $job): bool => $job->mailboxId === $one->id || $job->mailboxId === $two->id);
});

it('records health after a poll and keeps uidvalidity untouched by the fake driver', function () {
    $mailbox = fakeMailbox(['uidvalidity' => '111']);
    $mailbox->forceFill(['uidvalidity' => '111'])->save();
    pushEml($mailbox, '01-arabic-cv');

    pollMailbox($mailbox);

    // The fake driver reports no uidvalidity, so the stored value is kept as-is.
    expect($mailbox->refresh()->cursor)->toBe('01-arabic-cv')
        ->and($mailbox->uidvalidity)->toBe('111')
        ->and($mailbox->last_error)->toBeNull();
});

it('schedules polling every five minutes and the silence monitor hourly', function () {
    $events = collect(app(Schedule::class)->events());
    $poll = $events->first(fn ($event) => str_contains($event->command ?? '', 'recruit:poll-mailboxes'));
    $monitor = $events->first(fn ($event) => $event->description === MonitorMailboxSilence::class);
    expect($poll)->not->toBeNull()->and($poll->expression)->toBe('*/5 * * * *')
        ->and($monitor)->not->toBeNull()->and($monitor->expression)->toBe('0 * * * *');
});
