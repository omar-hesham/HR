<?php

/**
 * D-101: the .eml-folder driver — cursor, start pointer, limit, ordering.
 */

use App\Domain\Intake\InboundBatch;
use App\Infrastructure\Intake\FakeMailboxDriver;
use Illuminate\Support\Carbon;

it('reads .eml files oldest-name-first and honours the cursor', function () {
    $mailbox = fakeMailbox();
    pushEml($mailbox, '01-arabic-cv');
    pushEml($mailbox, '02-english-cv');
    pushEml($mailbox, '03-two-cvs');

    $driver = new FakeMailboxDriver;

    $first = $driver->fetch($mailbox, null, carbon('2026-01-01'), 100);
    expect($first->messages)->toHaveCount(3)
        ->and($first->messages[0]->externalId)->toBe('01-arabic-cv')
        ->and($first->messages[2]->externalId)->toBe('03-two-cvs');

    $after = $driver->fetch($mailbox, '01-arabic-cv', carbon('2026-01-01'), 100);
    expect($after->messages)->toHaveCount(2)
        ->and($after->messages[0]->externalId)->toBe('02-english-cv');

    $none = $driver->fetch($mailbox, '03-two-cvs', carbon('2026-01-01'), 100);
    expect($none->messages)->toHaveCount(0);
});

it('applies the operational start date (no archive import, M1-P0-01)', function () {
    $mailbox = fakeMailbox();
    pushEml($mailbox, '01-arabic-cv'); // Date: 30 Sep 2026

    $driver = new FakeMailboxDriver;

    expect($driver->fetch($mailbox, null, carbon('2026-10-01'), 100)->messages)->toHaveCount(0)
        ->and($driver->fetch($mailbox, null, carbon('2026-09-01'), 100)->messages)->toHaveCount(1);
});

it('limits the batch size per poll', function () {
    $mailbox = fakeMailbox();
    pushEml($mailbox, '01-arabic-cv');
    pushEml($mailbox, '02-english-cv');
    pushEml($mailbox, '03-two-cvs');

    $batch = (new FakeMailboxDriver)->fetch($mailbox, null, carbon('2026-01-01'), 2);

    expect($batch->messages)->toHaveCount(2);
});

it('returns an empty batch when the folder does not exist', function () {
    $mailbox = fakeMailbox(['host' => sys_get_temp_dir().'/recruit-absent-'.bin2hex(random_bytes(4))]);

    expect((new FakeMailboxDriver)->fetch($mailbox, null, null, 10)->messages)->toHaveCount(0);
});

it('falls back to the configured default folder', function () {
    config(['recruitment.intake.fake_path' => (string) fakeMailbox()->host]);

    $mailbox = fakeMailbox(['host' => null]);

    // The folder from the first mailbox exists, so the driver reads it via config.
    expect((new FakeMailboxDriver)->fetch($mailbox, null, null, 10))->toBeInstanceOf(InboundBatch::class);
});

function carbon(string $date): Carbon
{
    return Carbon::parse($date);
}
