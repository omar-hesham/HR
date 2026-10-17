<?php

/**
 * De-duplication at the message level and the file level (T-M1-01, M3-P0-01)
 * and poll idempotency (D-103).
 */

use App\Domain\Documents\Disposition;
use App\Domain\Intake\IntakeOutcome;
use App\Models\Document;
use App\Models\DocumentSource;
use App\Models\IntakeMessage;

beforeEach(function () {
    Storage::fake('private');
});

it('never ingests the same message twice across polls (T-M1-01)', function () {
    $mailbox = fakeMailbox();
    pushEml($mailbox, '01-arabic-cv');
    pushEml($mailbox, '02-english-cv');

    pollMailbox($mailbox);
    pollMailbox($mailbox);

    expect(IntakeMessage::query()->count())->toBe(2)
        ->and(DocumentSource::query()->count())->toBe(2)
        ->and(Document::query()->count())->toBe(2);
});

it('skips identical bytes arriving under a new external id (hash dedupe)', function () {
    $mailbox = fakeMailbox();
    pushEml($mailbox, '02-english-cv');
    pollMailbox($mailbox);

    // Same .eml again under a different file name.
    pushEml($mailbox, '99-again', (string) file_get_contents(emlFixture('02-english-cv')));
    pollMailbox($mailbox);

    expect(IntakeMessage::query()->count())->toBe(1);
});

it('links an identical CV file from a new message instead of re-processing it', function () {
    $mailbox = fakeMailbox();
    pushEml($mailbox, '02-english-cv');
    pollMailbox($mailbox);

    pushEml($mailbox, '10-duplicate-file');
    pollMailbox($mailbox);

    $messages = IntakeMessage::query()->orderBy('external_id')->get();

    expect($messages->count())->toBe(2)
        ->and($messages->firstWhere('external_id', '02-english-cv')->outcome)->toBe(IntakeOutcome::Processed->value)
        ->and($messages->firstWhere('external_id', '10-duplicate-file')->outcome)->toBe(IntakeOutcome::Processed->value)
        ->and(Document::query()->count())->toBe(1);

    $duplicate = DocumentSource::query()->where('disposition', Disposition::DuplicateLinked)->sole();

    expect($duplicate->document_id)->toBe(Document::query()->sole()->id)
        ->and($duplicate->application_id)->not->toBeNull();
});

it('marks a message with a duplicated Message-ID as ignored (layer 3)', function () {
    $mailbox = fakeMailbox();
    pushEml($mailbox, '02-english-cv');
    pollMailbox($mailbox);

    pushEml($mailbox, '11-duplicate-message-id');
    pollMailbox($mailbox);

    $duplicate = IntakeMessage::query()->where('external_id', '11-duplicate-message-id')->sole();

    expect($duplicate->outcome)->toBe(IntakeOutcome::IgnoredWithReason->value)
        ->and($duplicate->reason)->toBe('duplicate_message')
        ->and($duplicate->raw_path)->toBeNull()
        // The duplicate's attachment never enters the pipeline.
        ->and(DocumentSource::query()->count())->toBe(1);
});
