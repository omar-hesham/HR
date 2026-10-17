<?php

namespace App\Jobs;

use App\Domain\Applications\ApplicationStatus;
use App\Domain\Documents\DocumentStatus;
use App\Domain\Documents\ReviewReason;
use App\Domain\Text\TextNormalizer;
use App\Domain\Text\TextQualityChecker;
use App\Domain\Text\Wordlist;
use App\Infrastructure\Extraction\ExtractionException;
use App\Infrastructure\Extraction\TextExtractor;
use App\Infrastructure\Storage\EncryptedDocumentStore;
use App\Models\Application;
use App\Models\Document;
use App\Models\DocumentSource;
use App\Models\Extraction;
use App\Services\Applications\ApplicationStatusService;
use App\Services\Settings\SettingsRepository;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Extract → store encrypted raw text → NFKC normalize → quality gate (M2-P0-03/04, TXT-P0-03).
 *
 * Idempotent: unique per document, no overlap, and it exits when a current-version
 * extraction already exists. Payload holds the document id only (spec §9.4).
 */
final class ExtractDocumentText implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public int $uniqueFor = 900;

    public function __construct(public readonly int $documentId)
    {
        $this->onQueue((string) config('recruitment.queues.extraction', 'extraction'));
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function uniqueId(): string
    {
        return (string) $this->documentId;
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('extract-document-'.$this->documentId))->releaseAfter(30)->expireAfter(300)];
    }

    public function handle(
        EncryptedDocumentStore $store,
        TextExtractor $extractor,
        TextNormalizer $normalizer,
        SettingsRepository $settings,
        ApplicationStatusService $statuses,
    ): void {
        $document = Document::query()->find($this->documentId);

        // Deleted meanwhile (retention or data-subject request): never revive it.
        if ($document === null || $document->path === null || ! $store->exists($document->path)) {
            return;
        }

        if ($this->alreadyExtracted($document)) {
            return;
        }

        $document->forceFill(['status' => DocumentStatus::Extracting])->save();
        $this->moveApplications($document, ApplicationStatus::Received, ApplicationStatus::Processing, $statuses);

        $temp = $store->toTempFile($document->path, $document->extension);

        try {
            $extracted = $extractor->extract($temp, $document->extension);
        } catch (ExtractionException $e) {
            if ($e->transient && $this->attempts() < $this->tries) {
                $document->forceFill(['status' => DocumentStatus::Pending])->save();

                throw $e;
            }

            $this->recordFailure($document, $e->reason, $statuses);

            return;
        } finally {
            $store->deleteTemp($temp);
        }

        $normalized = $normalizer->normalize($extracted->text);
        $report = (new TextQualityChecker(Wordlist::default()))->check($normalized->text, $settings->qualityThresholds());

        DB::transaction(function () use ($document, $extracted, $normalized, $report): void {
            $existing = Document::query()->whereKey($document->id)->lockForUpdate()->first();
            if ($existing === null || $existing->path === null) {
                return;
            }
            Extraction::query()->create([
                'document_id' => $document->id,
                'extractor' => $extracted->extractor,
                'extractor_version' => (string) config('recruitment.extraction.extractor_version'),
                'status' => $report->passed() ? Extraction::STATUS_OK : Extraction::STATUS_NEEDS_REVIEW,
                'error_code' => $report->reason?->value,
                'raw_text_enc' => $extracted->text,
                'normalized_text_enc' => $normalized->text,
                'offset_map_enc' => json_encode($normalized->offsetRuns, JSON_THROW_ON_ERROR),
                'char_count' => $normalized->length(),
                'presentation_forms_replaced' => $normalized->presentationFormsReplaced,
                'quality_metrics' => $report->toArray(),
            ]);

            $document->forceFill([
                'status' => $report->passed() ? DocumentStatus::Extracted : DocumentStatus::NeedsReview,
                'review_reason' => $report->reason,
                'page_count' => $extracted->pageCount,
                'quality_flags' => $report->toArray(),
                'quality_score' => round($report->score, 4),
            ])->save();
        });

        if (! $report->passed() && $report->reason !== null) {
            $this->openReview($document, $report->reason, $statuses);
        }
    }

    /**
     * Called by the queue after the last attempt fails.
     */
    public function failed(?Throwable $exception): void
    {
        Log::warning('Document extraction failed permanently.', [
            'document_id' => $this->documentId,
            'error' => $exception === null ? null : $exception::class,
        ]);

        $document = Document::query()->find($this->documentId);

        if ($document !== null) {
            $this->recordFailure($document, ReviewReason::ExtractionFailed, app(ApplicationStatusService::class));
        }
    }

    private function alreadyExtracted(Document $document): bool
    {
        if (! in_array($document->status, [DocumentStatus::Extracted, DocumentStatus::NeedsReview], true)) {
            return false;
        }

        return $document->extractions()
            ->where('extractor_version', (string) config('recruitment.extraction.extractor_version'))
            ->exists();
    }

    private function recordFailure(Document $document, ReviewReason $reason, ApplicationStatusService $statuses): void
    {
        DB::transaction(function () use ($document, $reason): void {
            if (! Document::query()->whereKey($document->id)->lockForUpdate()->exists()) {
                return;
            }
            Extraction::query()->create([
                'document_id' => $document->id,
                'extractor' => $document->extension === 'pdf' ? 'pdftotext' : 'phpword',
                'extractor_version' => (string) config('recruitment.extraction.extractor_version'),
                'status' => Extraction::STATUS_FAILED,
                'error_code' => $reason->value,
            ]);

            $document->forceFill([
                'status' => DocumentStatus::NeedsReview,
                'review_reason' => $reason,
            ])->save();
        });

        $this->openReview($document, $reason, $statuses);
    }

    /**
     * Every source of the file and every waiting application becomes visible in review.
     */
    private function openReview(Document $document, ReviewReason $reason, ApplicationStatusService $statuses): void
    {
        DocumentSource::query()
            ->where('document_id', $document->id)
            ->whereNull('review_reason')
            ->get()
            ->each(fn (DocumentSource $source) => $source->openReview($reason));

        foreach ($this->linkedApplications($document) as $application) {
            if (in_array($application->status, [ApplicationStatus::Received, ApplicationStatus::Processing], true)) {
                $statuses->transition($application, ApplicationStatus::NeedsReview, reviewReason: $reason);
            }
        }
    }

    private function moveApplications(Document $document, ApplicationStatus $from, ApplicationStatus $to, ApplicationStatusService $statuses): void
    {
        foreach ($this->linkedApplications($document) as $application) {
            if ($application->status === $from) {
                $statuses->transition($application, $to);
            }
        }
    }

    /**
     * @return iterable<Application>
     */
    private function linkedApplications(Document $document): iterable
    {
        $ids = DocumentSource::query()->where('document_id', $document->id)->whereNotNull('application_id')->pluck('application_id');

        return Application::query()->whereIn('id', $ids)->get();
    }
}
