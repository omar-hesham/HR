<?php

namespace App\Actions\Documents;

use App\Domain\Applications\ApplicationStatus;
use App\Domain\Documents\Disposition;
use App\Domain\Documents\DocumentKind;
use App\Domain\Documents\DocumentStatus;
use App\Domain\Documents\FileSignature;
use App\Domain\Documents\ReviewReason;
use App\Domain\Documents\SourceChannel;
use App\Domain\Privacy\RetentionTrack;
use App\Infrastructure\Storage\EncryptedDocumentStore;
use App\Jobs\ExtractDocumentText;
use App\Models\Application;
use App\Models\Candidate;
use App\Models\Document;
use App\Models\DocumentSource;
use App\Models\RecruitmentJob;
use App\Models\User;
use App\Services\Applications\ApplicationStatusService;
use App\Services\Settings\SettingsRepository;
use finfo;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Manual upload (source = upload), single or bulk (M2-P0-01/02, M3-P0-01 hash part).
 *
 * Every file gets a document_sources row first (WF-P0-01); invalid files stay visible
 * in "Needs review"; identical files are linked, never re-processed; a job is never
 * inherited from an earlier application. Extraction runs in a queued job.
 */
final class UploadDocuments
{
    public function __construct(
        private readonly EncryptedDocumentStore $store,
        private readonly SettingsRepository $settings,
        private readonly ApplicationStatusService $statuses,
    ) {}

    /**
     * @param  list<UploadedFile>  $files
     */
    public function handle(array $files, User $actor, ?RecruitmentJob $job = null): UploadResult
    {
        Gate::forUser($actor)->authorize('upload', [Document::class, $job]);

        $maxFiles = $this->settings->int('upload.max_batch_files');

        if (count($files) > $maxFiles) {
            throw new UploadLimitExceeded('app.upload.errors.too_many_files', $maxFiles);
        }

        $maxBatches = $this->settings->int('upload.max_batches_per_hour');

        if (! RateLimiter::attempt('uploads:'.$actor->id, $maxBatches, static fn (): bool => true, 3600)) {
            throw new UploadLimitExceeded('app.upload.errors.too_many_batches', $maxBatches);
        }

        $result = new UploadResult((string) Str::uuid());

        foreach ($files as $index => $file) {
            $this->handleOne($file, $index, $result, $actor, $job);
        }

        activity('documents')
            ->causedBy($actor)
            ->event('documents.uploaded')
            ->withProperties([
                'batch' => $result->batch,
                'job_id' => $job?->id,
                'stored' => $result->count(Disposition::Stored),
                'duplicates' => $result->count(Disposition::DuplicateLinked),
                'rejected' => $result->count(Disposition::Rejected),
            ])
            ->log('documents.uploaded');

        return $result;
    }

    private function handleOne(UploadedFile $file, int $index, UploadResult $result, User $actor, ?RecruitmentJob $job): void
    {
        $extension = strtolower($file->getClientOriginalExtension());

        // Accounting row first, before any slow or failing work (WF-P0-01).
        $source = DocumentSource::query()->create([
            'source' => SourceChannel::Upload,
            'attachment_key' => $result->batch.':'.$index,
            'attachment_kind' => DocumentKind::Cv,
            'disposition' => Disposition::Pending,
            'original_filename_enc' => mb_substr($file->getClientOriginalName(), 0, 255),
            'reported_mime' => mb_substr((string) $file->getClientMimeType(), 0, 128),
            'size' => (int) $file->getSize(),
            'upload_batch' => $result->batch,
            'uploaded_by' => $actor->id,
        ]);

        $reason = $this->validate($file, $extension);

        if ($reason !== null) {
            $this->reject($source, $reason, $result, $index);

            return;
        }

        $bytes = (string) file_get_contents((string) $file->getRealPath());

        if (! FileSignature::matchesExtension($bytes, $extension)) {
            $this->reject($source, ReviewReason::SignatureMismatch, $result, $index);

            return;
        }

        $sha256 = hash('sha256', $bytes);
        $existing = Document::query()->where('sha256', $sha256)->first();

        if ($existing !== null) {
            $this->linkDuplicate($existing, $source, $actor, $job, $result, $index);

            return;
        }

        try {
            $this->storeNew($bytes, $sha256, $extension, $source, $actor, $job);
            $result->add($index, $source->id, Disposition::Stored);
        } catch (UniqueConstraintViolationException) {
            // Same file uploaded concurrently: treat as an identical duplicate.
            $existing = Document::query()->where('sha256', $sha256)->firstOrFail();
            $this->linkDuplicate($existing, $source, $actor, $job, $result, $index);
        }
    }

    private function validate(UploadedFile $file, string $extension): ?ReviewReason
    {
        /** @var list<string> $allowed */
        $allowed = config('recruitment.extraction.allowed_extensions', []);

        if (! $file->isValid()) {
            return ReviewReason::CorruptFile;
        }

        if (! in_array($extension, $allowed, true)) {
            return ReviewReason::InvalidType;
        }

        $size = (int) $file->getSize();

        if ($size === 0) {
            return ReviewReason::EmptyFile;
        }

        if ($size > $this->settings->int('upload.max_file_mb') * 1024 * 1024) {
            return ReviewReason::FileTooLarge;
        }

        return null;
    }

    private function reject(DocumentSource $source, ReviewReason $reason, UploadResult $result, int $index): void
    {
        $source->forceFill(['disposition' => Disposition::Rejected])->save();
        $source->openReview($reason);
        $result->add($index, $source->id, Disposition::Rejected, $reason);
    }

    private function storeNew(string $bytes, string $sha256, string $extension, DocumentSource $source, User $actor, ?RecruitmentJob $job): void
    {
        $candidateUuid = (string) Str::uuid();
        $documentUuid = (string) Str::uuid();
        $path = $this->store->put($candidateUuid, $documentUuid, $bytes);

        try {
            DB::transaction(function () use ($bytes, $sha256, $extension, $source, $actor, $job, $candidateUuid, $documentUuid, $path): void {
                $candidate = Candidate::query()->create([
                    'uuid' => $candidateUuid,
                    'received_at' => now(),
                    'retention_hard_due_at' => now()->addDays($this->settings->int('retention.hard_max_days')),
                ]);

                $document = Document::query()->create([
                    'uuid' => $documentUuid,
                    'candidate_id' => $candidate->id,
                    'path' => $path,
                    'sha256' => $sha256,
                    'mime' => (string) (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes),
                    'extension' => $extension,
                    'size' => strlen($bytes),
                    'kind' => DocumentKind::Cv,
                    'status' => DocumentStatus::Pending,
                    'encrypted' => true,
                ]);

                $candidate->forceFill(['active_document_id' => $document->id])->save();
                $application = $this->createApplication($candidate, $job, $actor);

                $source->forceFill([
                    'document_id' => $document->id,
                    'application_id' => $application->id,
                    'disposition' => Disposition::Stored,
                ])->save();

                ExtractDocumentText::dispatch($document->id)->afterCommit();
            });
        } catch (UniqueConstraintViolationException $e) {
            $this->store->disk()->delete($path);

            throw $e;
        }
    }

    private function linkDuplicate(Document $document, DocumentSource $source, User $actor, ?RecruitmentJob $job, UploadResult $result, int $index): void
    {
        DB::transaction(function () use ($document, $source, $actor, $job): void {
            $candidate = $document->candidate;

            $application = $candidate === null ? null : $candidate->applications()
                ->where('recruitment_job_id', $job?->id)
                ->whereNotIn('status', [ApplicationStatus::Deleted->value])
                ->first();

            if ($candidate !== null && $application === null) {
                $application = $this->createApplication($candidate, $job, $actor);
                $this->syncWithDocument($application, $document);
            }

            $source->forceFill([
                'document_id' => $document->id,
                'application_id' => $application?->id,
                'disposition' => Disposition::DuplicateLinked,
            ])->save();

            if ($document->status === DocumentStatus::NeedsReview) {
                $source->openReview(ReviewReason::DuplicateOfUnreadable);
            }
        });

        $result->add($index, $source->id, Disposition::DuplicateLinked, $document->status === DocumentStatus::NeedsReview ? ReviewReason::DuplicateOfUnreadable : null);
    }

    private function createApplication(Candidate $candidate, ?RecruitmentJob $job, User $actor): Application
    {
        $application = Application::query()->create([
            'uuid' => (string) Str::uuid(),
            'candidate_id' => $candidate->id,
            'recruitment_job_id' => $job?->id,
            'source' => SourceChannel::Upload,
            'status' => ApplicationStatus::Received,
            'received_at' => now(),
            'last_activity_at' => now(),
            'retention_track' => RetentionTrack::Applicant,
            'created_by' => $actor->id,
        ]);

        $this->statuses->recordCreated($application, $actor);

        return $application;
    }

    /**
     * A new application for an already-processed file starts where the file is.
     */
    private function syncWithDocument(Application $application, Document $document): void
    {
        if ($document->status === DocumentStatus::Extracted) {
            $this->statuses->transition($application, ApplicationStatus::Processing);
        } elseif ($document->status === DocumentStatus::NeedsReview) {
            $this->statuses->transition($application, ApplicationStatus::NeedsReview, reviewReason: $document->review_reason ?? ReviewReason::ExtractionFailed);
        }
    }
}
