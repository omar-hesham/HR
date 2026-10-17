<?php

/**
 * End-to-end intake through the fake driver (kickoff §5.2, M1-P0-02/03/05):
 * raw-first, classification with reasons, nothing silently dropped.
 */

use App\Domain\Documents\Disposition;
use App\Domain\Documents\DocumentKind;
use App\Domain\Documents\ReviewReason;
use App\Domain\Intake\IntakeOutcome;
use App\Infrastructure\Storage\EncryptedDocumentStore;
use App\Models\Document;
use App\Models\DocumentSource;
use App\Models\IntakeMessage;
use App\Models\RecruitmentJob;

beforeEach(function () {
    Storage::fake('private');
});

it('stores the raw .eml encrypted with its hash before any parsing', function () {
    $mailbox = fakeMailbox();
    pushEml($mailbox, '01-arabic-cv');
    $original = (string) file_get_contents(emlFixture('01-arabic-cv'));

    pollMailbox($mailbox);

    $message = IntakeMessage::query()->sole();

    expect($message->raw_sha256)->toBe(hash('sha256', $original))
        ->and((int) $message->raw_size)->toBe(strlen($original))
        ->and($message->raw_path)->toStartWith('intake/');

    $stored = (string) Storage::disk('private')->get($message->raw_path);

    expect($stored)->not->toBe($original)
        ->and(str_contains($stored, 'Ahmed'))->toBeFalse()
        ->and(app(EncryptedDocumentStore::class)->get($message->raw_path))->toBe($original);
});

it('records the encrypted envelope and a pseudonymous sender HMAC', function () {
    $mailbox = fakeMailbox();
    pushEml($mailbox, '02-english-cv');
    pollMailbox($mailbox);

    $message = IntakeMessage::query()->sole();
    $raw = DB::table('intake_messages')->where('id', $message->id)->first();

    expect($message->subject_enc)->toBe('Application: accountant position')
        ->and($message->sender_enc)->toBe('applicant2@example.test')
        ->and($raw->subject_enc)->not->toContain('accountant')
        ->and($message->sender_hmac)->toBe(hash_hmac('sha256', 'applicant2@example.test', config('recruitment.hash.dedup_key')))
        ->and($message->message_id_header)->toBe('<fixture-02@example.test>');
});

it('feeds the CV into the shared pipeline with source = email', function () {
    $mailbox = fakeMailbox();
    pushEml($mailbox, '02-english-cv');
    pollMailbox($mailbox);

    $message = IntakeMessage::query()->sole();
    $source = $message->documentSources()->sole();
    $application = $source->application;
    $document = $source->document;

    expect($source->disposition)->toBe(Disposition::Stored)
        ->and($source->source->value)->toBe('email')
        ->and($source->external_reference)->toBe('<fixture-02@example.test>')
        ->and($application->source->value)->toBe('email')
        ->and($application->external_reference)->toBe('<fixture-02@example.test>')
        ->and($document)->not->toBeNull()
        ->and($document->candidate)->not->toBeNull()
        ->and($message->outcome)->toBe(IntakeOutcome::Processed->value)
        ->and($message->reason)->toBe('cv_processed')
        ->and($message->visible_at)->not->toBeNull();
});

it('treats each CV in a multi-CV message as an independent item', function () {
    $mailbox = fakeMailbox();
    pushEml($mailbox, '03-two-cvs');
    pollMailbox($mailbox);

    expect(Document::query()->count())->toBe(2)
        ->and(DocumentSource::query()->count())->toBe(2)
        ->and(IntakeMessage::query()->sole()->documentSources()->pluck('application_id')->unique()->count())->toBe(2);
});

it('classifies mixed attachments: CV stored, image reviewed, other logged with a reason', function () {
    $mailbox = fakeMailbox();
    pushEml($mailbox, '04-mixed-attachments');
    pollMailbox($mailbox);

    $sources = IntakeMessage::query()->sole()->documentSources()->get();
    $image = $sources->firstWhere('original_filename_enc', 'personal-photo.png');
    $note = $sources->firstWhere('original_filename_enc', 'notes.txt');
    $cv = $sources->firstWhere('original_filename_enc', 'mona-cv.pdf');

    expect($cv->disposition)->toBe(Disposition::Stored)
        ->and($image->attachment_kind)->toBe(DocumentKind::SupportingDocument)
        ->and($image->disposition)->toBe(Disposition::Excluded)
        ->and($image->reason)->toBe('image_file_type')
        ->and($image->review_reason)->toBe(ReviewReason::ImageAttachment)
        ->and($image->review_resolved_at)->toBeNull()
        // Image is never stored and never OCR'd (D-106).
        ->and($image->document_id)->toBeNull()
        ->and($note->attachment_kind)->toBe(DocumentKind::Other)
        ->and($note->disposition)->toBe(Disposition::Excluded)
        ->and($note->reason)->toBe('unsupported_file_type')
        ->and($note->review_reason)->toBeNull()
        ->and(IntakeMessage::query()->sole()->outcome)->toBe(IntakeOutcome::NeedsReview->value)
        ->and(IntakeMessage::query()->sole()->reason)->toBe('image_attachment');
});

it('sends an image-only CV to review without OCR and without storing it', function () {
    $mailbox = fakeMailbox();
    pushEml($mailbox, '05-image-only-cv');
    pollMailbox($mailbox);

    $message = IntakeMessage::query()->sole();
    $source = $message->documentSources()->sole();

    expect($message->outcome)->toBe(IntakeOutcome::NeedsReview->value)
        ->and($source->review_reason)->toBe(ReviewReason::ImageAttachment)
        ->and($source->document_id)->toBeNull()
        ->and(Document::query()->count())->toBe(0);
});

it('keeps a forwarded message (message/rfc822) for review as uncertain', function () {
    $mailbox = fakeMailbox();
    pushEml($mailbox, '06-forwarded-cv');
    pollMailbox($mailbox);

    $source = IntakeMessage::query()->sole()->documentSources()->sole();

    expect($source->attachment_kind)->toBe(DocumentKind::Uncertain)
        ->and($source->reason)->toBe('forwarded_message')
        ->and($source->review_reason)->toBe(ReviewReason::UncertainAttachment)
        ->and($source->document_id)->toBeNull()
        ->and(IntakeMessage::query()->sole()->outcome)->toBe(IntakeOutcome::NeedsReview->value);
});

it('ignores a message without attachments and drops the stored raw (minimal record)', function () {
    $mailbox = fakeMailbox();
    pushEml($mailbox, '08-no-attachments');
    $original = (string) file_get_contents(emlFixture('08-no-attachments'));
    pollMailbox($mailbox);

    $message = IntakeMessage::query()->sole();

    expect($message->outcome)->toBe(IntakeOutcome::IgnoredWithReason->value)
        ->and($message->reason)->toBe('no_attachments')
        ->and($message->raw_path)->toBeNull()
        ->and($message->raw_sha256)->toBe(hash('sha256', $original))
        ->and(DocumentSource::query()->count())->toBe(0);
});

it('logs an inline signature image with a reason but opens no review item', function () {
    $mailbox = fakeMailbox();
    pushEml($mailbox, '13-inline-image');
    pollMailbox($mailbox);

    $sources = IntakeMessage::query()->sole()->documentSources;
    $logo = $sources->firstWhere('original_filename_enc', 'logo.png');

    expect($logo->disposition)->toBe(Disposition::Excluded)
        ->and($logo->reason)->toBe('inline_image')
        ->and($logo->review_reason)->toBeNull()
        ->and($sources->count())->toBe(2)
        ->and(IntakeMessage::query()->sole()->outcome)->toBe(IntakeOutcome::Processed->value);
});

it('marks a message unreadable when the raw bytes are not parseable', function () {
    $mailbox = fakeMailbox();
    pushEml($mailbox, '99-garbage', "not an email at all\r\njust text\r\n");
    pollMailbox($mailbox);

    $message = IntakeMessage::query()->sole();

    expect($message->outcome)->toBe(IntakeOutcome::NeedsReview->value)
        ->and($message->reason)->toBe('parse_failed')
        ->and($message->last_error)->toBe('parse_failed')
        ->and($message->raw_path)->not->toBeNull();
});

it('resolves the job from an explicit title and from an approved alias', function () {
    $job = RecruitmentJob::factory()->create([
        'title_ar' => 'مشرف إنتاج',
        'title_en' => 'Production Supervisor',
        'aliases' => ['فني كهرباء'],
    ]);

    $mailbox = fakeMailbox();
    pushEml($mailbox, '12-job-title');
    pushEml($mailbox, '01-arabic-cv');
    pollMailbox($mailbox);

    $byTitle = IntakeMessage::query()->where('external_id', '12-job-title')->sole();
    $byAlias = IntakeMessage::query()->where('external_id', '01-arabic-cv')->sole();

    expect($byTitle->job_hint_id)->toBe($job->id)
        ->and($byTitle->documentSources()->sole()->application->recruitment_job_id)->toBe($job->id)
        ->and($byAlias->job_hint_id)->toBe($job->id)
        ->and($byAlias->documentSources()->sole()->application->recruitment_job_id)->toBe($job->id);
});

it('leaves the application unassigned when the job is ambiguous', function () {
    RecruitmentJob::factory()->create(['title_en' => 'Electrical Technician']);
    RecruitmentJob::factory()->create(['title_en' => 'Mechanical Technician']);

    $mailbox = fakeMailbox();
    pushEml($mailbox, '99-ambiguous', buildEml(
        'someone@example.test',
        '<runtime-ambiguous@example.test>',
        'Applying for Electrical Technician or Mechanical Technician',
        [['filename' => 'cv.pdf', 'bytes' => (string) file_get_contents(cvFixture('02_en_accountant.pdf')), 'type' => 'application/pdf']],
    ));
    pollMailbox($mailbox);

    $application = IntakeMessage::query()->sole()->documentSources()->sole()->application;

    expect($application->recruitment_job_id)->toBeNull()
        ->and(IntakeMessage::query()->sole()->job_hint_id)->toBeNull();
});

it('keeps unknown attachments visible even without CV filename keywords', function () {
    $mailbox = fakeMailbox();
    pushEml($mailbox, '99-unknown', buildEml('synthetic@example.test', '<unknown@example.test>', 'Application', [
        ['filename' => 'attachment.dat', 'bytes' => 'synthetic unknown format', 'type' => 'application/octet-stream'],
    ]));
    pollMailbox($mailbox);
    expect(IntakeMessage::query()->sole()->outcome)->toBe('needs_review')
        ->and(DocumentSource::query()->sole()->review_reason)->toBe(ReviewReason::UncertainAttachment)
        ->and(Document::query()->count())->toBe(0);
});

it('accepts inline and extensionless CVs based on their real content', function (string $filename, bool $inline) {
    Queue::fake();
    $mailbox = fakeMailbox();
    $raw = buildEml('synthetic@example.test', '<inline-cv@example.test>', 'CV', [
        ['filename' => $filename, 'bytes' => file_get_contents(cvFixture('02_en_accountant.pdf')), 'type' => 'application/pdf'],
    ]);
    if ($inline) {
        $raw = str_replace('Content-Disposition: attachment;', 'Content-Disposition: inline;', $raw);
    }
    pushEml($mailbox, '99-content', $raw);
    pollMailbox($mailbox);
    expect(IntakeMessage::query()->sole()->outcome)->toBe('processed')
        ->and(Document::query()->sole()->extension)->toBe('pdf');
})->with([['inline.pdf', true], ['attachment', false]]);
