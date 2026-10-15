<?php

/**
 * T-M2-01 / T-M2-02 / T-M2-03 / T-M3-01 (hash part) / T-M3-03 (job not inherited):
 * upload → encrypted store → queued extraction → NFKC → quality gate → Needs review.
 * Nothing is silently dropped. Uses the synthetic fixtures in tests/Fixtures/cvs/.
 */

use App\Actions\Documents\UploadDocuments;
use App\Actions\Documents\UploadLimitExceeded;
use App\Actions\Documents\UploadResult;
use App\Actions\Review\ResolveReviewItem;
use App\Domain\Access\Role;
use App\Domain\Applications\ApplicationStatus;
use App\Domain\Documents\Disposition;
use App\Domain\Documents\DocumentStatus;
use App\Domain\Documents\ReviewReason;
use App\Domain\Documents\SourceChannel;
use App\Domain\Text\TextNormalizer;
use App\Infrastructure\Extraction\Binaries;
use App\Infrastructure\Extraction\TextExtractor;
use App\Infrastructure\Storage\EncryptedDocumentStore;
use App\Jobs\ExtractDocumentText;
use App\Models\Application;
use App\Models\Document;
use App\Models\DocumentSource;
use App\Models\Extraction;
use App\Models\RecruitmentJob;
use App\Models\User;
use App\Services\Applications\ApplicationStatusService;
use App\Services\Settings\SettingsRepository;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\Process\Process;

beforeEach(fn () => Storage::fake('private'));

/**
 * @param  list<string>  $files
 */
function uploadFixtures(array $files, ?RecruitmentJob $job = null, ?User $user = null): UploadResult
{
    $user ??= userWithRole(Role::Recruiter, $job !== null ? [$job] : []);

    return app(UploadDocuments::class)->handle(array_map(fn (string $f) => fixtureUpload($f), $files), $user, $job);
}

function sourceFor(UploadResult $result, int $index): DocumentSource
{
    return DocumentSource::query()->findOrFail($result->items[$index]['source_id']);
}

it('accounts for every file of a bulk upload, valid or not (nothing silently dropped)', function () {
    requirePdftotext();
    $manifest = json_decode((string) file_get_contents(cvFixture('manifest.json')), true);
    $files = array_keys($manifest['files']);
    $job = RecruitmentJob::factory()->create();

    $result = uploadFixtures($files, $job);

    expect(DocumentSource::query()->count())->toBe(count($files))
        ->and(count($result->items))->toBe(count($files));

    foreach ($files as $index => $file) {
        $expected = $manifest['files'][$file]['expected'];
        $source = sourceFor($result, $index)->fresh(['document']);

        match (true) {
            $expected === 'extracted' => expect($source->document?->status)->toBe(DocumentStatus::Extracted, $file),
            $expected === 'duplicate' => expect($source->disposition)->toBe(Disposition::DuplicateLinked, $file),
            str_starts_with($expected, 'needs_review:') => expect($source->review_reason?->value)->toBe(substr($expected, 13), $file)
                ->and($source->document?->status)->toBe(DocumentStatus::NeedsReview, $file),
            str_starts_with($expected, 'rejected:') => expect($source->disposition)->toBe(Disposition::Rejected, $file)
                ->and($source->review_reason?->value)->toBe(substr($expected, 9), $file),
        };
    }

    // Every file is either processed or visible in the Needs-review list.
    $visible = DocumentSource::query()->openReview()->count();
    $ok = DocumentSource::query()->whereNull('review_reason')->count();
    expect($visible + $ok)->toBe(count($files));
});

it('stores files encrypted outside the web root with a SHA-256 of the original', function () {
    $result = uploadFixtures(['09_ar_storekeeper.docx']);
    $document = sourceFor($result, 0)->document;
    $original = (string) file_get_contents(cvFixture('09_ar_storekeeper.docx'));
    $stored = (string) Storage::disk('private')->get($document->path);

    expect($document->sha256)->toBe(hash('sha256', $original))
        ->and($document->path)->toStartWith('candidates/')
        ->and($document->path)->not->toContain('storekeeper')
        ->and($stored)->not->toBe($original)
        ->and(str_contains($stored, 'word/document.xml'))->toBeFalse()
        ->and(app(EncryptedDocumentStore::class)->get($document->path))->toBe($original)
        ->and(config('filesystems.disks.private.root'))->not->toContain(public_path());
});

it('encrypts extracted text at rest and keeps it readable through the model', function () {
    $result = uploadFixtures(['10_en_quality.docx']);
    $extraction = sourceFor($result, 0)->document->latestExtraction;
    $raw = DB::table('extractions')->where('id', $extraction->id)->first();

    expect($raw->raw_text_enc)->not->toContain('Omneya')
        ->and($raw->normalized_text_enc)->not->toContain('quality')
        ->and($extraction->normalizedText())->toContain('Omneya Samir Khalil')
        ->and($extraction->quality_metrics['reason'])->toBeNull();
});

it('links an identical file instead of re-processing it', function () {
    requirePdftotext();
    $job = RecruitmentJob::factory()->create();
    $first = uploadFixtures(['02_en_accountant.pdf'], $job);
    $second = uploadFixtures(['08_en_accountant_duplicate.pdf'], $job);

    $original = sourceFor($first, 0);
    $duplicate = sourceFor($second, 0);

    expect($duplicate->disposition)->toBe(Disposition::DuplicateLinked)
        ->and($duplicate->document_id)->toBe($original->document_id)
        ->and($duplicate->application_id)->toBe($original->application_id)
        ->and(Document::query()->count())->toBe(1)
        ->and(Extraction::query()->count())->toBe(1)
        ->and(Application::query()->count())->toBe(1);
});

it('creates a new application, without inheriting the job, when the same file arrives for another job or none', function () {
    $jobA = RecruitmentJob::factory()->create();
    $jobB = RecruitmentJob::factory()->create();
    $owner = userWithRole(Role::Owner);

    $a = uploadFixtures(['10_en_quality.docx'], $jobA, $owner);
    $b = uploadFixtures(['10_en_quality.docx'], $jobB, $owner);
    $none = uploadFixtures(['10_en_quality.docx'], null, $owner);

    $appA = sourceFor($a, 0)->application;
    $appB = sourceFor($b, 0)->application;
    $appNone = sourceFor($none, 0)->application;

    expect($appA->recruitment_job_id)->toBe($jobA->id)
        ->and($appB->recruitment_job_id)->toBe($jobB->id)
        ->and($appNone->recruitment_job_id)->toBeNull()
        ->and($appA->candidate_id)->toBe($appB->candidate_id)
        ->and(Extraction::query()->count())->toBe(1)
        // the new applications start where the file already is
        ->and($appB->status)->toBe(ApplicationStatus::Processing)
        ->and($appNone->status)->toBe(ApplicationStatus::Processing);
});

it('records source = upload on the application and the file', function () {
    $result = uploadFixtures(['11_docx_no_contacts.docx']);
    $source = sourceFor($result, 0);

    expect($source->source)->toBe(SourceChannel::Upload)
        ->and($source->application->source)->toBe(SourceChannel::Upload)
        // a CV without contact details still gets a provisional candidate
        ->and($source->application->candidate->email)->toBeNull();
});

it('routes image-only, protected, reversed and corrupt files to Needs review with their reason', function (string $file, ReviewReason $reason) {
    if (str_ends_with($file, '.pdf')) {
        requirePdftotext();
    }

    $source = sourceFor(uploadFixtures([$file]), 0);

    expect($source->review_reason)->toBe($reason)
        ->and($source->application->status)->toBe(ApplicationStatus::NeedsReview)
        ->and($source->application->review_reason)->toBe($reason)
        ->and($source->document->status)->toBe(DocumentStatus::NeedsReview);
})->with([
    ['06_image_only_scan.pdf', ReviewReason::ImageOnly],
    ['07_password_protected.pdf', ReviewReason::PasswordProtected],
    ['13_reversed_arabic.docx', ReviewReason::SuspectedReversed],
    ['14_corrupt.pdf', ReviewReason::CorruptFile],
    ['16_legacy.doc', ReviewReason::DocConversionDisabled],
]);

it('rejects wrong types, mismatched content and oversized files with a visible reason', function () {
    app(SettingsRepository::class)->update(['upload.max_file_mb' => 1], null);
    $big = sys_get_temp_dir().'/recruit-big-'.bin2hex(random_bytes(4)).'.pdf';
    file_put_contents($big, '%PDF-1.4'.str_repeat('x', 1024 * 1024 + 10));
    $files = [
        fixtureUpload('15_wrong_type.pdf'),
        new UploadedFile($big, 'big.pdf', null, null, true),
        fixtureUpload('02_en_accountant.pdf', 'notes.txt'),
    ];

    $result = app(UploadDocuments::class)->handle($files, userWithRole(Role::Recruiter), null);

    expect(array_map(fn (array $i) => $i['reason'], $result->items))->toBe([
        ReviewReason::SignatureMismatch,
        ReviewReason::FileTooLarge,
        ReviewReason::InvalidType,
    ])
        ->and(Document::query()->count())->toBe(0)
        ->and(DocumentSource::query()->openReview()->count())->toBe(3);
});

it('repairs Arabic presentation forms from the PDF and records the quality metrics', function () {
    requirePdftotext();
    $source = sourceFor(uploadFixtures(['01_ar_electrician.pdf']), 0);
    $extraction = $source->document->latestExtraction;

    expect($extraction->presentation_forms_replaced)->toBeGreaterThan(50)
        ->and(preg_match('/[\x{FB50}-\x{FDFF}\x{FE70}-\x{FEFF}]/u', (string) $extraction->normalizedText()))->toBe(0)
        ->and(preg_match('/[\x{FB50}-\x{FDFF}\x{FE70}-\x{FEFF}]/u', (string) $extraction->rawText()))->toBe(1)
        ->and($extraction->quality_metrics)->toHaveKeys(['letter_ratio', 'wordlist_ratio', 'reversed_ratio'])
        ->and((float) $source->document->quality_score)->toBeGreaterThan(0.9);
});

it('applies quality thresholds from settings', function () {
    app(SettingsRepository::class)->update(['quality.min_wordlist_ratio' => 0.99], null);

    $source = sourceFor(uploadFixtures(['10_en_quality.docx']), 0);

    expect($source->review_reason)->toBe(ReviewReason::LowQuality);
});

it('queues extraction with retries, backoff and a timeout', function () {
    Queue::fake();
    $source = sourceFor(uploadFixtures(['10_en_quality.docx']), 0);

    Queue::assertPushedOn('extraction', ExtractDocumentText::class, function (ExtractDocumentText $job) use ($source): bool {
        return $job->documentId === $source->document_id
            && $job->tries === 3
            && $job->backoff() === [30, 120]
            && $job->timeout === 120
            && $job->uniqueId() === (string) $source->document_id;
    });

    expect($source->document->status)->toBe(DocumentStatus::Pending)
        ->and($source->application->status)->toBe(ApplicationStatus::Received);
});

it('is idempotent when the extraction job runs twice', function () {
    $document = sourceFor(uploadFixtures(['12_table_cv.docx']), 0)->document;

    (new ExtractDocumentText($document->id))->handle(
        app(EncryptedDocumentStore::class),
        app(TextExtractor::class),
        new TextNormalizer,
        app(SettingsRepository::class),
        app(ApplicationStatusService::class),
    );

    expect(Extraction::query()->where('document_id', $document->id)->count())->toBe(1);
});

it('does not revive a document whose file was deleted', function () {
    Queue::fake();
    $document = sourceFor(uploadFixtures(['12_table_cv.docx']), 0)->document;
    Storage::disk('private')->delete($document->path);

    dispatch_sync(new ExtractDocumentText($document->id));

    expect(Extraction::query()->count())->toBe(0);
});

it('marks a file as needing review after the last failed attempt', function () {
    Queue::fake();
    $source = sourceFor(uploadFixtures(['12_table_cv.docx']), 0);

    (new ExtractDocumentText($source->document_id))->failed(new RuntimeException('synthetic'));

    expect($source->fresh()->review_reason)->toBe(ReviewReason::ExtractionFailed)
        ->and($source->application->fresh()->status)->toBe(ApplicationStatus::NeedsReview);
});

it('lets a recruiter resolve a review item with a note and logs it', function () {
    $source = sourceFor(uploadFixtures(['13_reversed_arabic.docx']), 0);
    $recruiter = userWithRole(Role::Recruiter);

    app(ResolveReviewItem::class)->resolve($source, 'Asked the candidate for a text PDF.', $recruiter);

    expect($source->fresh()->review_resolved_by)->toBe($recruiter->id)
        ->and(DocumentSource::query()->openReview()->count())->toBe(0)
        ->and(Activity::query()->where('event', 'review.resolved')->exists())->toBeTrue();
});

it('re-runs extraction on request and keeps the earlier attempt as history', function () {
    $source = sourceFor(uploadFixtures(['13_reversed_arabic.docx']), 0);
    app(SettingsRepository::class)->update(['quality.max_reversed_ratio' => 1.0, 'quality.min_wordlist_ratio' => 0.0], null);

    app(ResolveReviewItem::class)->retry($source, userWithRole(Role::Owner));

    expect(Extraction::query()->where('document_id', $source->document_id)->count())->toBe(2)
        ->and($source->document->fresh()->status)->toBe(DocumentStatus::Extracted)
        ->and($source->application->fresh()->status)->toBe(ApplicationStatus::Processing);
});

it('refuses uploads from roles without the permission or outside the job scope', function (Role $role) {
    app(UploadDocuments::class)->handle([fixtureUpload('10_en_quality.docx')], userWithRole($role), RecruitmentJob::factory()->create());
})->with([Role::Coordinator, Role::Management, Role::Operator, Role::HiringManager])->throws(AuthorizationException::class);

it('limits the number of files per batch', function () {
    app(SettingsRepository::class)->update(['upload.max_batch_files' => 1], null);

    app(UploadDocuments::class)->handle([fixtureUpload('10_en_quality.docx'), fixtureUpload('12_table_cv.docx')], userWithRole(Role::Recruiter));
})->throws(UploadLimitExceeded::class);

it('extracts legacy DOC files when LibreOffice is available and the flag is on', function () {
    if (Binaries::libreoffice() === null) {
        $this->markTestSkipped('LibreOffice (soffice) is not installed; DOC conversion is tested on the server image.');
    }

    // Build a real synthetic .doc from a DOCX fixture with the same binary.
    $dir = sys_get_temp_dir().'/recruit-doc-'.bin2hex(random_bytes(4));
    mkdir($dir);
    copy(cvFixture('10_en_quality.docx'), $dir.'/synthetic.docx');
    $process = new Process([
        Binaries::libreoffice(), '--headless', '--convert-to', 'doc', '--outdir', $dir, $dir.'/synthetic.docx',
    ]);
    $process->setTimeout(120)->run();
    expect(is_file($dir.'/synthetic.doc'))->toBeTrue();

    config(['recruitment.extraction.doc_enabled' => true]);
    $upload = new UploadedFile($dir.'/synthetic.doc', 'synthetic.doc', null, null, true);
    $result = app(UploadDocuments::class)->handle([$upload], userWithRole(Role::Recruiter));
    $document = sourceFor($result, 0)->document;

    expect($document->status)->toBe(DocumentStatus::Extracted)
        ->and($document->latestExtraction->extractor)->toBe('libreoffice+phpword')
        ->and($document->latestExtraction->normalizedText())->toContain('Omneya');
});
