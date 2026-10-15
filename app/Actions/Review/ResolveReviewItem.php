<?php

namespace App\Actions\Review;

use App\Domain\Applications\ApplicationStatus;
use App\Domain\Documents\DocumentStatus;
use App\Jobs\ExtractDocumentText;
use App\Models\Application;
use App\Models\DocumentSource;
use App\Models\User;
use App\Services\Applications\ApplicationStatusService;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * Closes a "Needs review" item with a note, or re-queues extraction. Both are logged.
 * Resolving never deletes anything and never rejects an applicant.
 */
final class ResolveReviewItem
{
    public function __construct(private readonly ApplicationStatusService $statuses) {}

    public function resolve(DocumentSource $source, string $note, User $actor): void
    {
        Gate::forUser($actor)->authorize('resolve', $source);

        if (trim($note) === '') {
            throw new InvalidArgumentException('A note is required.');
        }

        $source->forceFill([
            'review_resolved_at' => now(),
            'review_resolved_by' => $actor->id,
            'review_note_enc' => $note,
        ])->save();

        activity('documents')
            ->causedBy($actor)
            ->performedOn($source)
            ->event('review.resolved')
            ->withProperties(['reason' => $source->review_reason?->value])
            ->log('review.resolved');
    }

    /**
     * Re-run extraction for a stored file (e.g. after a transient failure or a settings change).
     */
    public function retry(DocumentSource $source, User $actor): bool
    {
        Gate::forUser($actor)->authorize('resolve', $source);

        $document = $source->document;

        if ($document === null || $document->path === null) {
            return false;
        }

        // Earlier extraction rows stay as history; Pending makes the job run again.
        $document->forceFill(['status' => DocumentStatus::Pending, 'review_reason' => null])->save();

        DocumentSource::query()
            ->where('document_id', $document->id)
            ->whereNull('review_resolved_at')
            ->update(['review_reason' => null, 'review_opened_at' => null]);

        $applicationIds = DocumentSource::query()->where('document_id', $document->id)->whereNotNull('application_id')->pluck('application_id');

        foreach (Application::query()->whereIn('id', $applicationIds)->get() as $application) {
            if ($application->status === ApplicationStatus::NeedsReview) {
                $this->statuses->transition($application, ApplicationStatus::Processing, $actor, 'retry_extraction');
            }
        }

        activity('documents')
            ->causedBy($actor)
            ->performedOn($source)
            ->event('review.retry_extraction')
            ->withProperties(['document' => $document->uuid])
            ->log('review.retry_extraction');

        ExtractDocumentText::dispatch($document->id);

        return true;
    }
}
