<?php

use App\Actions\Documents\UploadDocuments;
use App\Domain\Access\Role;
use App\Domain\Documents\Disposition;
use App\Domain\Documents\ReviewReason;
use App\Domain\Intake\Contracts\MessageParser;
use App\Domain\Intake\ParsedInboundMessage;
use App\Infrastructure\Intake\WebklexMessageParser;
use App\Infrastructure\Storage\EncryptedDocumentStore;
use App\Jobs\ExtractDocumentText;
use App\Jobs\SendPrivacyNotice;
use App\Mail\PrivacyNoticeMail;
use App\Models\Document;
use App\Models\DocumentSource;
use App\Models\IntakeMessage;
use App\Models\NoticeOutbox;
use App\Services\Malware\MalwareScanner;
use App\Services\Malware\ScanVerdict;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('private');
    Queue::fake();
    Mail::fake();
});

it('has a recoverable encrypted original when the MIME parser is entered', function () {
    app()->instance(MessageParser::class, new class implements MessageParser
    {
        public function parse(string $raw): ParsedInboundMessage
        {
            $message = IntakeMessage::query()->sole();
            expect($message->outcome)->toBe('pending')
                ->and($message->raw_sha256)->toBe(hash('sha256', $raw))
                ->and(app(EncryptedDocumentStore::class)->get($message->raw_path))->toBe($raw);

            return (new WebklexMessageParser)->parse($raw);
        }
    });
    $mailbox = fakeMailbox();
    pushEml($mailbox, '02-english-cv');
    pollMailbox($mailbox);
    expect($mailbox->refresh()->cursor)->toBe('02-english-cv');
});

it('retries a failed raw write without parsing or advancing the pointer', function () {
    $disk = Storage::disk('private');
    $attempts = 0;
    $faultyDisk = Mockery::mock(Filesystem::class);
    $faultyDisk->shouldReceive('put')->andReturnUsing(function ($path, $bytes) use ($disk, &$attempts) {
        return ++$attempts === 1 ? false : $disk->put($path, $bytes);
    });
    Storage::shouldReceive('disk')->with('private')->andReturn($faultyDisk);
    $mailbox = fakeMailbox();
    pushEml($mailbox, '02-english-cv');

    expect(fn () => pollMailbox($mailbox))->toThrow(RuntimeException::class, 'intake_processing_failed');
    $message = IntakeMessage::query()->sole();
    expect($message->raw_path)->toBeNull()
        ->and($message->outcome)->toBe('pending')
        ->and($mailbox->refresh()->cursor)->toBeNull()
        ->and(DocumentSource::query()->count())->toBe(0);

    pollMailbox($mailbox);
    expect(IntakeMessage::query()->count())->toBe(1)
        ->and($message->refresh()->outcome)->toBe('processed')
        ->and($disk->exists($message->raw_path))->toBeTrue()
        ->and(Document::query()->count())->toBe(1)
        ->and($mailbox->refresh()->cursor)->toBe('02-english-cv');
});

it('resumes a partially processed multi-CV receipt without duplicate sources or extraction', function () {
    app()->instance(MalwareScanner::class, new class implements MalwareScanner
    {
        private int $calls = 0;

        public function scan(string $bytes, string $filename): ScanVerdict
        {
            if (++$this->calls === 2) {
                throw new RuntimeException('synthetic scanner outage with secret=never-log-this');
            }

            return ScanVerdict::clean();
        }
    });
    $mailbox = fakeMailbox();
    pushEml($mailbox, '03-two-cvs');
    expect(fn () => pollMailbox($mailbox))->toThrow(RuntimeException::class, 'intake_processing_failed');
    expect($mailbox->refresh()->cursor)->toBeNull()
        ->and($mailbox->last_success_at)->toBeNull()
        ->and($mailbox->last_error)->toBe('intake_processing_failed')
        ->and(IntakeMessage::query()->sole()->outcome)->toBe('pending')
        ->and(Document::query()->count())->toBe(1)
        ->and(DocumentSource::query()->count())->toBe(2);

    pollMailbox($mailbox);
    expect($mailbox->refresh()->cursor)->toBe('03-two-cvs')
        ->and($mailbox->last_error)->toBeNull()
        ->and(IntakeMessage::query()->sole()->outcome)->toBe('processed')
        ->and(Document::query()->count())->toBe(2)
        ->and(DocumentSource::query()->count())->toBe(2)
        ->and(NoticeOutbox::query()->count())->toBe(1);
    Queue::assertPushed(ExtractDocumentText::class, 2);
});

it('uses generated storage identifiers for untrusted attachment filenames', function (string $filename) {
    $mailbox = fakeMailbox();
    pushEml($mailbox, '99-traversal', buildEml('synthetic@example.test', '<traversal@example.test>', 'CV', [
        ['filename' => $filename, 'bytes' => file_get_contents(cvFixture('02_en_accountant.pdf')), 'type' => 'application/pdf'],
    ]));
    pollMailbox($mailbox);
    $document = Document::query()->sole();
    expect($document->path)->toMatch('~^candidates/[a-f0-9-]{36}/documents/[a-f0-9-]{36}\.bin$~')
        ->and(DocumentSource::query()->sole()->disposition)->toBe(Disposition::Stored);
    foreach (Storage::disk('private')->allFiles() as $path) {
        expect($path)->not->toContain('..')->not->toContain('escaped');
    }
})->with(['../../escaped.pdf', '..\\..\\escaped.pdf', 'C:\\escaped.pdf']);

it('reserves one notice across mailboxes and sends it even with another attachment in review', function () {
    $one = fakeMailbox();
    pushEml($one, '04-mixed-attachments');
    pollMailbox($one);
    expect(IntakeMessage::query()->sole()->outcome)->toBe('needs_review')
        ->and(NoticeOutbox::query()->count())->toBe(1);

    $two = fakeMailbox();
    $raw = str_replace('<fixture-04@example.test>', '<cross-mailbox@example.test>', file_get_contents(emlFixture('04-mixed-attachments')));
    pushEml($two, '99-second-mailbox', $raw);
    pollMailbox($two);
    expect(IntakeMessage::query()->count())->toBe(2)
        ->and(NoticeOutbox::query()->count())->toBe(1);
});

it('recovers an undispatched notice from the outbox on the next scheduled poll', function () {
    $mailbox = fakeMailbox();
    pushEml($mailbox, '02-english-cv');
    pollMailbox($mailbox);
    $notice = NoticeOutbox::query()->sole();
    $this->artisan('recruit:poll-mailboxes')->assertSuccessful();
    Queue::assertPushed(SendPrivacyNotice::class, fn ($job) => $job->noticeId === $notice->id);
    expect($notice->refresh()->status)->toBe('pending');
});

it('never sends a second copy when an outbox job is delivered twice', function () {
    $mailbox = fakeMailbox();
    pushEml($mailbox, '02-english-cv');
    pollMailbox($mailbox);
    $notice = NoticeOutbox::query()->sole();
    $job = new SendPrivacyNotice($notice->id);
    $job->handle();
    $job->handle();
    Mail::assertSent(PrivacyNoticeMail::class, 1);
    Mail::assertNothingQueued();
    expect($notice->refresh()->status)->toBe('sent')->and($notice->attempts)->toBe(1);
});

it('defaults new mailbox start pointers to now rather than importing its archive', function () {
    $this->travelTo(Carbon::parse('2026-10-17 12:00:00 UTC'));
    $mailbox = fakeMailbox(['start_at' => null]);
    pushEml($mailbox, '02-english-cv');
    pollMailbox($mailbox);
    expect($mailbox->refresh()->start_at->equalTo(now()))->toBeTrue()
        ->and(IntakeMessage::query()->count())->toBe(0);
});

it('keeps invalid HTTP uploads as corrupt review items after sharing the CV pipeline', function () {
    $actor = userWithRole(Role::Recruiter);
    $file = new UploadedFile(cvFixture('02_en_accountant.pdf'), 'cv.pdf', 'application/pdf', UPLOAD_ERR_PARTIAL, true);
    app(UploadDocuments::class)->handle([$file], $actor);
    $source = DocumentSource::query()->sole();
    expect($source->disposition)->toBe(Disposition::Rejected)
        ->and($source->review_reason)->toBe(ReviewReason::CorruptFile)
        ->and(Document::query()->count())->toBe(0);
});
