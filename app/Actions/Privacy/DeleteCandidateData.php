<?php

namespace App\Actions\Privacy;

use App\Domain\Applications\ApplicationStatus;
use App\Domain\Intake\SenderHmac;
use App\Domain\Privacy\DeletionReason;
use App\Models\Application;
use App\Models\Candidate;
use App\Models\DeletionLedgerEntry;
use App\Models\Document;
use App\Models\DocumentSource;
use App\Models\DuplicateResolution;
use App\Models\IntakeMessage;
use App\Models\ReviewExport;
use App\Models\Score;
use App\Services\Applications\ApplicationStatusService;
use App\Services\Privacy\DeletionLedger;
use App\Services\Privacy\ErasureFiles;
use App\Services\Privacy\IdentityGroup;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** All erasure entry points, including restore replay, converge here. */
final class DeleteCandidateData
{
    public function __construct(private readonly ErasureFiles $files) {}

    public function candidate(int $candidateId, DeletionReason $reason): void
    {
        $ids = app(IdentityGroup::class)->ids($candidateId);
        DB::transaction(function () use ($ids, $reason): void {
            $people = Candidate::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
            $applications = Application::query()->whereIn('candidate_id', $ids)->orderBy('id')->lockForUpdate()->get();
            foreach ($applications as $application) {
                $this->eraseApplication($application, $reason);
            }
            $documents = Document::query()->whereIn('candidate_id', $ids)->get();
            foreach ($documents as $document) {
                $this->eraseDocument($document, $reason);
            }
            $this->scrubIdentity($ids);
            foreach ($people as $person) {
                if ($person->getAttribute('erased_at') === null) {
                    $this->ledger(['candidate:'.$person->uuid], null, $reason);
                }
            }
            app(DeletionLedger::class)->publish();
        });
        $this->files->purge();
    }

    public function application(int $applicationId, DeletionReason $reason): void
    {
        $application = Application::query()->find($applicationId);
        if ($application === null || $application->status === ApplicationStatus::Deleted) {
            app(DeletionLedger::class)->publish();
            $this->files->purge();

            return;
        }
        $ids = app(IdentityGroup::class)->ids($application->candidate_id);
        $remaining = Application::query()->whereIn('candidate_id', $ids)->whereKeyNot($applicationId)->where('status', '!=', 'deleted')->exists();
        if (! $remaining) {
            $this->candidate($application->candidate_id, $reason);

            return;
        }
        DB::transaction(function () use ($application, $reason): void {
            $locked = Application::query()->whereKey($application->id)->lockForUpdate()->firstOrFail();
            $this->eraseApplication($locked, $reason);
            $tombstone = Candidate::query()->create(['uuid' => (string) Str::uuid(), 'received_at' => $locked->received_at]);
            $tombstone->forceFill(['erased_at' => now()])->save();
            $locked->forceFill(['candidate_id' => $tombstone->id])->save();
            app(DeletionLedger::class)->publish();
        });
        $this->files->purge();
    }

    private function eraseApplication(Application $application, DeletionReason $reason): void
    {
        if ($application->status === ApplicationStatus::Deleted) {
            return;
        }
        $sources = DocumentSource::query()->where('application_id', $application->id)->get();
        $documents = $sources->pluck('document_id')->filter()->unique()->all();
        foreach ($sources->pluck('intake_message_id')->filter()->unique() as $messageId) {
            // A raw message shared with another live application stays until the last one ends (T-M10-02).
            if (! $this->messageInUse((int) $messageId, $application->id)) {
                $this->eraseMessage((int) $messageId);
            }
        }
        $this->eraseExports($application->id);
        foreach (DB::table('privacy_artifacts')->where('application_id', $application->id)->get() as $artifact) {
            $this->files->queue('private', $artifact->path);
        }
        DB::table('privacy_artifacts')->where('application_id', $application->id)->update(['purged_at' => now(), 'application_id' => null]);
        $this->scrubAudit(Application::class, [$application->id]);
        $this->scrubAudit(Score::class, $application->scores()->pluck('id')->all());
        $this->scrubAudit(DocumentSource::class, $sources->modelKeys());
        DB::table('calibration_entries')->where('application_id', $application->id)->delete();
        DB::table('ai_work_items')->where('application_id', $application->id)->delete();
        DB::table('field_corrections')->where('application_id', $application->id)->delete();
        DB::table('human_field_verifications')->where('application_id', $application->id)->delete();
        DB::table('scores')->where('application_id', $application->id)->delete();
        DB::table('notes')->where('application_id', $application->id)->delete();
        DB::table('status_events')->where('application_id', $application->id)->delete();
        DB::table('document_sources')->where('application_id', $application->id)->delete();
        app(ApplicationStatusService::class)->transition($application, ApplicationStatus::Deleted);
        // The deletion event replaces the personal timeline with an opaque tombstone.
        DB::table('status_events')->where('application_id', $application->id)->update(['reason_enc' => null]);
        $application->forceFill(['current_score_id' => null, 'external_reference' => null, 'created_by' => null, 'review_reason' => null])->save();
        $this->ledger(['application:'.$application->uuid], null, $reason);
        foreach (Document::query()->whereIn('id', $documents)->get() as $document) {
            if (! DocumentSource::query()->where('document_id', $document->id)->whereHas('application', fn ($q) => $q->where('status', '!=', 'deleted'))->exists()) {
                $this->eraseDocument($document, $reason);
            }
        }
    }

    private function eraseDocument(Document $document, DeletionReason $reason): void
    {
        $locked = Document::query()->whereKey($document->id)->lockForUpdate()->first();
        if ($locked === null) {
            return;
        }
        // A cross-application reference always wins over ordinary file deletion.
        if (DocumentSource::query()->where('document_id', $locked->id)->whereHas('application', fn ($q) => $q->where('status', '!=', 'deleted'))->exists()) {
            return;
        }
        foreach (DocumentSource::query()->where('document_id', $locked->id)->whereNotNull('intake_message_id')->pluck('intake_message_id') as $messageId) {
            if (! $this->messageInUse((int) $messageId)) {
                $this->eraseMessage((int) $messageId);
            }
        }
        $this->files->queue((string) config('recruitment.storage.disk'), $locked->path);
        $this->scrubAudit(Document::class, [$locked->id]);
        DB::table('document_sources')->where('document_id', $locked->id)->delete();
        DB::table('candidates')->where('active_document_id', $locked->id)->update(['active_document_id' => null]);
        $this->ledger(['document:'.$locked->uuid], app(SenderHmac::class)->hash('document:'.$locked->sha256), $reason);
        DB::table('documents')->where('id', $locked->id)->delete();
    }

    private function messageInUse(int $messageId, ?int $exceptApplicationId = null): bool
    {
        return DocumentSource::query()->where('intake_message_id', $messageId)
            ->when($exceptApplicationId !== null, fn ($q) => $q->where('application_id', '!=', $exceptApplicationId))
            ->whereHas('application', fn ($q) => $q->where('status', '!=', ApplicationStatus::Deleted->value))
            ->exists();
    }

    private function eraseMessage(int $messageId): void
    {
        $message = DB::table('intake_messages')->where('id', $messageId)->lockForUpdate()->first();
        if ($message === null) {
            return;
        }
        $this->files->queue((string) config('recruitment.storage.disk'), $message->raw_path);
        if ($message->raw_sha256 !== null) {
            $this->ledger(['message:'.hash('sha256', (string) $messageId)], app(SenderHmac::class)->hash('message:'.$message->raw_sha256), DeletionReason::ErasureRequest);
        }
        DB::table('document_sources')->where('intake_message_id', $messageId)->update(['original_filename_enc' => null, 'external_reference' => null, 'review_note_enc' => null]);
        DB::table('notice_outbox')->where('intake_message_id', $messageId)->delete();
        DB::table('ai_work_items')->where('intake_message_id', $messageId)->delete();
        DB::table('ai_consents')->where('intake_message_id', $messageId)->delete();
        $this->scrubAudit(IntakeMessage::class, [$messageId]);
        DB::table('intake_messages')->where('id', $messageId)->update(['raw_path' => null, 'raw_sha256' => null, 'raw_size' => null, 'subject_enc' => null, 'sender_enc' => null, 'sender_hmac' => null, 'message_id_header' => null, 'last_error' => null, 'external_id' => 'erased-'.$messageId, 'reason' => 'data_erased', 'outcome' => 'ignored_with_reason']);
    }

    private function eraseExports(int $applicationId): void
    {
        foreach (ReviewExport::query()->get() as $artifact) {
            if (in_array($applicationId, $artifact->application_ids, true)) {
                $this->files->queue('review_exports', $artifact->path);
                $artifact->forceFill(['purged_at' => now(), 'purpose_enc' => '', 'application_ids' => []])->save();
                $this->scrubAudit(ReviewExport::class, [$artifact->id]);
            }
        }
        DB::table('notice_outbox')->where('application_id', $applicationId)->delete();
    }

    /** @param list<int> $ids */
    private function scrubIdentity(array $ids): void
    {
        foreach (DB::table('privacy_requests')->whereIn('candidate_id', $ids)->get() as $request) {
            foreach (DB::table('privacy_artifacts')->where('privacy_request_id', $request->id)->whereNull('purged_at')->get() as $artifact) {
                $this->files->queue('private', $artifact->path);
            }
            DB::table('privacy_artifacts')->where('privacy_request_id', $request->id)->update(['purged_at' => now()]);
        }
        DB::table('privacy_requests')->whereIn('candidate_id', $ids)->update(['candidate_id' => null, 'details_enc' => null, 'verification_enc' => null, 'decision_enc' => null, 'channel_enc' => null, 'notification_enc' => null]);
        DB::table('ai_consents')->whereIn('candidate_id', $ids)->delete();
        $duplicates = DB::table('duplicate_resolutions')->whereIn('existing_candidate_id', $ids)->orWhereIn('incoming_candidate_id', $ids);
        $this->scrubAudit(DuplicateResolution::class, (clone $duplicates)->pluck('id')->all());
        $duplicates->delete();
        $this->scrubAudit(Candidate::class, $ids);
        DB::table('candidates')->whereIn('id', $ids)->update(['name' => null, 'email' => null, 'phone' => null, 'phone_original' => null, 'email_hmac' => null, 'phone_hmac' => null, 'search_normalized' => null, 'active_document_id' => null, 'merged_into_id' => null, 'erased_at' => now()]);
    }

    /** @param list<int|string> $ids */
    private function scrubAudit(string $type, array $ids): void
    {
        if ($ids !== []) {
            DB::table('activity_log')->where('subject_type', $type)->whereIn('subject_id', $ids)->update(['subject_type' => null, 'subject_id' => null, 'properties' => '{}', 'attribute_changes' => null, 'description' => 'resource_erased']);
        }
    }

    /** @param list<string> $refs */
    private function ledger(array $refs, ?string $hmac, DeletionReason $reason): void
    {
        DeletionLedgerEntry::query()->create(['opaque_resource_ids' => $refs, 'source_hmac' => $hmac, 'reason_code' => $reason->value, 'deleted_at' => now()]);
    }
}
