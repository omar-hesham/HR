<?php

namespace App\Actions\Documents;

use App\Domain\Applications\ApplicationStatus;
use App\Domain\Documents\Disposition;
use App\Domain\Documents\DocumentKind;
use App\Domain\Documents\DocumentStatus;
use App\Domain\Documents\FileSignature;
use App\Domain\Documents\ReviewReason;
use App\Domain\Documents\SourceChannel;
use App\Domain\Intake\SenderHmac;
use App\Domain\Privacy\RetentionTrack;
use App\Infrastructure\Storage\EncryptedDocumentStore;
use App\Jobs\ExtractDocumentText;
use App\Models\Application;
use App\Models\Candidate;
use App\Models\DeletionLedgerEntry;
use App\Models\Document;
use App\Models\DocumentSource;
use App\Models\RecruitmentJob;
use App\Models\User;
use App\Services\Applications\ApplicationStatusService;
use App\Services\Malware\MalwareScanner;
use App\Services\Settings\SettingsRepository;
use finfo;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The one per-file CV pipeline, shared by manual upload (source = upload) and
 * mail intake (source = email): accounting row first (WF-P0-01), validation,
 * malware scan, SHA-256 duplicate detection (M3-P0-01), encrypted storage and
 * a provisional candidate + application, then the queued extraction job.
 */
final class StoreCvFile
{
    public function __construct(
        private readonly EncryptedDocumentStore $store,
        private readonly SettingsRepository $settings,
        private readonly ApplicationStatusService $statuses,
        private readonly MalwareScanner $scanner,
    ) {}

    public function store(
        string $bytes,
        string $originalFilename,
        string $reportedMime,
        SourceChannel $channel,
        ?User $actor = null,
        ?RecruitmentJob $job = null,
        ?int $intakeMessageId = null,
        ?string $attachmentKey = null,
        ?string $uploadBatch = null,
        ?string $externalReference = null,
        ?ReviewReason $receiptError = null,
    ): StoreOutcome {
        if (DeletionLedgerEntry::query()->where('source_hmac', app(SenderHmac::class)->hash('document:'.hash('sha256', $bytes)))->exists()) {
            $source = DocumentSource::query()->create(['source' => $channel, 'attachment_key' => (string) Str::uuid(), 'attachment_kind' => DocumentKind::Cv, 'disposition' => Disposition::Excluded, 'reason' => 'data_erased']);

            return new StoreOutcome($source->id, Disposition::Excluded, null, 'data_erased');
        }
        $extension = strtolower(pathinfo($originalFilename, PATHINFO_EXTENSION));
        if ($channel === SourceChannel::Email && $extension === '') {
            $extension = FileSignature::detect($bytes) ?? '';
        }

        // Accounting row first, before any slow or failing work (WF-P0-01).
        $source = $intakeMessageId === null ? new DocumentSource : DocumentSource::query()->firstOrNew([
            'intake_message_id' => $intakeMessageId, 'attachment_key' => $attachmentKey,
        ]);
        $source->fill([
            'source' => $channel,
            'external_reference' => $externalReference,
            'attachment_key' => $attachmentKey ?? (string) Str::uuid(),
            'attachment_kind' => DocumentKind::Cv,
            'disposition' => Disposition::Pending,
            'original_filename_enc' => mb_substr($originalFilename, 0, 255),
            'reported_mime' => mb_substr($reportedMime, 0, 128),
            'size' => strlen($bytes),
            'upload_batch' => $uploadBatch,
            'uploaded_by' => $actor?->id,
            'intake_message_id' => $intakeMessageId,
        ])->save();

        $reason = $receiptError ?? $this->validate($bytes, $extension);

        if ($reason !== null) {
            $this->reject($source, $reason);

            return new StoreOutcome($source->id, Disposition::Rejected, $reason, $reason->value);
        }

        $verdict = $this->scanner->scan($bytes, $originalFilename);

        if (! $verdict->clean) {
            // Infected: quarantine encrypted outside the pipeline; never extracted (M2-P0-02).
            $this->store->putQuarantined(hash('sha256', $bytes), $bytes);
            $source->forceFill([
                'disposition' => Disposition::Excluded, 'reason' => ReviewReason::MalwareDetected->value,
                'review_reason' => ReviewReason::MalwareDetected, 'review_opened_at' => now(),
            ])->save();

            return new StoreOutcome($source->id, Disposition::Excluded, ReviewReason::MalwareDetected, ReviewReason::MalwareDetected->value);
        }

        if (! FileSignature::matchesExtension($bytes, $extension)) {
            $this->reject($source, ReviewReason::SignatureMismatch);

            return new StoreOutcome($source->id, Disposition::Rejected, ReviewReason::SignatureMismatch, ReviewReason::SignatureMismatch->value);
        }

        $sha256 = hash('sha256', $bytes);
        $existing = Document::query()->where('sha256', $sha256)->first();

        if ($existing !== null) {
            return $this->linkDuplicate($existing, $source, $channel, $actor, $job, $externalReference);
        }

        try {
            return $this->storeNew($bytes, $sha256, $extension, $source, $channel, $actor, $job, $externalReference);
        } catch (UniqueConstraintViolationException) {
            // Same file stored concurrently: treat as an identical duplicate.
            $existing = Document::query()->where('sha256', $sha256)->firstOrFail();

            return $this->linkDuplicate($existing, $source, $channel, $actor, $job, $externalReference);
        }
    }

    private function validate(string $bytes, string $extension): ?ReviewReason
    {
        /** @var list<string> $allowed */
        $allowed = config('recruitment.extraction.allowed_extensions', []);

        if (! in_array($extension, $allowed, true)) {
            return ReviewReason::InvalidType;
        }

        $size = strlen($bytes);

        if ($size === 0) {
            return ReviewReason::EmptyFile;
        }

        if ($size > $this->settings->int('upload.max_file_mb') * 1024 * 1024) {
            return ReviewReason::FileTooLarge;
        }

        return null;
    }

    private function reject(DocumentSource $source, ReviewReason $reason): void
    {
        $source->forceFill([
            'disposition' => Disposition::Rejected, 'reason' => $reason->value,
            'review_reason' => $reason, 'review_opened_at' => now(),
        ])->save();
    }

    private function storeNew(string $bytes, string $sha256, string $extension, DocumentSource $source, SourceChannel $channel, ?User $actor, ?RecruitmentJob $job, ?string $externalReference): StoreOutcome
    {
        $candidateUuid = (string) Str::uuid();
        $documentUuid = (string) Str::uuid();
        $path = $this->store->put($candidateUuid, $documentUuid, $bytes);

        try {
            $applicationId = null;

            DB::transaction(function () use ($bytes, $sha256, $extension, $source, $actor, $job, $candidateUuid, $documentUuid, $path, &$applicationId): void {
                $candidate = Candidate::query()->create([
                    'uuid' => $candidateUuid,
                    'received_at' => $source->intakeMessage->received_at ?? now(),
                    'retention_hard_due_at' => ($source->intakeMessage->received_at ?? now())->copy()->addDays($this->settings->int('retention.hard_max_days')),
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
                $application = $this->createApplication($candidate, $job, $actor, $source);
                $applicationId = $application->id;

                $source->forceFill([
                    'document_id' => $document->id,
                    'application_id' => $application->id,
                    'disposition' => Disposition::Stored,
                    'reason' => 'cv_stored',
                ])->save();

                ExtractDocumentText::dispatch($document->id)->afterCommit();
            });
        } catch (UniqueConstraintViolationException $e) {
            $this->store->delete($path);

            throw $e;
        }

        return new StoreOutcome($source->id, Disposition::Stored, null, 'cv_stored', $source->document_id, $applicationId, true);
    }

    private function linkDuplicate(Document $document, DocumentSource $source, SourceChannel $channel, ?User $actor, ?RecruitmentJob $job, ?string $externalReference): StoreOutcome
    {
        $applicationId = null;
        $created = false;

        DB::transaction(function () use ($document, $source, $actor, $job, &$applicationId, &$created): void {
            $candidate = $document->candidate;

            $application = $candidate === null ? null : $candidate->applications()
                ->where('recruitment_job_id', $job?->id)
                ->whereNotIn('status', [ApplicationStatus::Deleted->value])
                ->first();

            if ($candidate !== null && $application === null) {
                $application = $this->createApplication($candidate, $job, $actor, $source);
                $this->syncWithDocument($application, $document);
                $created = true;
            }

            $applicationId = $application?->id;

            $source->forceFill([
                'document_id' => $document->id,
                'application_id' => $application?->id,
                'disposition' => Disposition::DuplicateLinked,
                'reason' => 'duplicate_file',
            ])->save();

            if ($document->status === DocumentStatus::NeedsReview) {
                $source->openReview(ReviewReason::DuplicateOfUnreadable);
            }
        });

        return new StoreOutcome(
            $source->id,
            Disposition::DuplicateLinked,
            $document->status === DocumentStatus::NeedsReview ? ReviewReason::DuplicateOfUnreadable : null,
            $document->status === DocumentStatus::NeedsReview ? ReviewReason::DuplicateOfUnreadable->value : null,
            $document->id,
            $applicationId,
            $created,
        );
    }

    private function createApplication(Candidate $candidate, ?RecruitmentJob $job, ?User $actor, DocumentSource $source): Application
    {
        $application = Application::query()->create([
            'uuid' => (string) Str::uuid(),
            'candidate_id' => $candidate->id,
            'recruitment_job_id' => $job?->id,
            'source' => $source->source,
            'external_reference' => $source->external_reference,
            'status' => ApplicationStatus::Received,
            'received_at' => $source->intakeMessage->received_at ?? now(),
            'last_activity_at' => $source->intakeMessage->received_at ?? now(),
            'retention_track' => RetentionTrack::Applicant,
            'created_by' => $actor?->id,
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
