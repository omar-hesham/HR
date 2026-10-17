<?php

namespace App\Services\Intake;

use App\Actions\Documents\StoreCvFile;
use App\Domain\Documents\Disposition;
use App\Domain\Documents\DocumentKind;
use App\Domain\Documents\ReviewReason;
use App\Domain\Documents\SourceChannel;
use App\Domain\Intake\AttachmentClass;
use App\Domain\Intake\AttachmentClassifier;
use App\Domain\Intake\AutoReplyDetector;
use App\Domain\Intake\Contracts\MessageParser;
use App\Domain\Intake\InboundAttachment;
use App\Domain\Intake\IntakeOutcome;
use App\Domain\Intake\IntakeReason;
use App\Domain\Intake\JobResolver;
use App\Domain\Intake\MessageParseFailure;
use App\Domain\Intake\SenderHmac;
use App\Infrastructure\Storage\EncryptedDocumentStore;
use App\Models\DeletionLedgerEntry;
use App\Models\DocumentSource;
use App\Models\IntakeMessage;
use App\Models\Mailbox;
use App\Models\RecruitmentJob;
use App\Services\Ai\AiWorkCoordinator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Ingests one raw message (kickoff §5.2 raw-first):
 *
 * 1. skip if the same bytes (SHA-256) or the same (mailbox, external id) exist,
 * 2. store the raw .eml ENCRYPTED and create the accounting row BEFORE parsing,
 * 3. parse, classify every attachment (each outcome gets a reason, M1-P0-03),
 * 4. feed CVs into the shared StoreCvFile pipeline with source = email,
 * 5. decide the message outcome, then queue the privacy notice if allowed.
 */
final class ProcessInboundMessage
{
    public function __construct(
        private readonly MessageParser $parser,
        private readonly AttachmentClassifier $classifier,
        private readonly AutoReplyDetector $autoReplies,
        private readonly JobResolver $jobResolver,
        private readonly SenderHmac $senderHmac,
        private readonly EncryptedDocumentStore $store,
        private readonly StoreCvFile $storeCvFile,
        private readonly PrivacyNoticeGate $notices,
    ) {}

    public function process(Mailbox $mailbox, string $externalId, string $raw, ?Carbon $receivedAt = null): ProcessedMessageResult
    {
        $rawSha256 = hash('sha256', $raw);
        if (DeletionLedgerEntry::query()->where('source_hmac', $this->senderHmac->hash('message:'.$rawSha256))->exists()) {
            return ProcessedMessageResult::skipped('data_erased');
        }

        $message = IntakeMessage::query()
            ->where('mailbox_id', $mailbox->id)->where('external_id', $externalId)->first();

        if ($message !== null && $message->outcome !== IntakeOutcome::Pending->value) {
            return ProcessedMessageResult::skipped('external_id_duplicate');
        }

        $sameHash = IntakeMessage::query()->where('raw_sha256', $rawSha256)
            ->when($message !== null, fn ($query) => $query->where('id', '!=', $message->id))->first();

        if ($sameHash !== null) {
            if ($sameHash->outcome === IntakeOutcome::Pending->value) {
                throw new \RuntimeException('duplicate_message_pending');
            }

            return ProcessedMessageResult::skipped('raw_hash_duplicate');
        }

        if ($message === null) {
            $message = IntakeMessage::query()->create([
                'mailbox_id' => $mailbox->id,
                'source' => SourceChannel::Email,
                'external_id' => $externalId,
                'raw_sha256' => $rawSha256,
                'raw_size' => strlen($raw),
                'received_at' => $receivedAt ?? now(),
                'outcome' => IntakeOutcome::Pending,
            ]);
        }

        if ($message->raw_sha256 !== $rawSha256) {
            throw new \RuntimeException('message_identity_changed');
        }

        if ($message->raw_path === null) {
            $rawPath = $this->store->putIntake((string) Str::uuid(), $raw);
            $message->forceFill(['raw_path' => $rawPath])->save();
        }

        try {
            $parsed = $this->parser->parse($raw);
        } catch (MessageParseFailure) {
            $this->finish($message, IntakeOutcome::NeedsReview, IntakeReason::ParseFailed->value, 'parse_failed');

            return new ProcessedMessageResult(true, $message->id, IntakeReason::ParseFailed->value, $message->received_at, true);
        }

        // Idempotency layer 3: same Message-ID already ingested under another id (D-103).
        if ($parsed->messageIdHeader !== null) {
            $duplicate = IntakeMessage::query()
                ->where('message_id_header', $parsed->messageIdHeader)
                ->where('id', '!=', $message->id)
                ->first();

            if ($duplicate !== null && $duplicate->outcome === IntakeOutcome::Pending->value) {
                throw new \RuntimeException('duplicate_message_pending');
            }

            if ($duplicate !== null) {
                $this->dropRaw($message);
                $this->finish($message, IntakeOutcome::IgnoredWithReason, IntakeReason::DuplicateMessage->value);

                return new ProcessedMessageResult(true, $message->id, IntakeReason::DuplicateMessage->value, $message->received_at);
            }
        }

        $hint = $this->jobResolver->resolve($parsed);
        $job = $hint->jobId === null ? null : RecruitmentJob::query()->find($hint->jobId);

        $message->forceFill([
            'message_id_header' => $parsed->messageIdHeader === null ? null : mb_substr($parsed->messageIdHeader, 0, 512),
            'subject_enc' => $parsed->subject,
            'sender_enc' => $parsed->fromAddress,
            'sender_hmac' => $this->senderHmac->hash($parsed->fromAddress),
            'received_at' => $receivedAt ?? $parsed->receivedAt,
            'job_hint_id' => $hint->jobId,
        ])->save();

        $autoReply = $this->autoReplies->detect($parsed->headers, $parsed->fromAddress);
        $maxBytes = $this->maxAttachmentBytes();

        $cvReceived = false;
        $applicationCreated = false;
        $needsReview = false;
        $reviewReason = null;

        foreach ($parsed->attachments as $attachment) {
            $existingSource = $message->documentSources()->where('attachment_key', $externalId.':'.$attachment->partId)->first();

            if ($existingSource !== null && $existingSource->disposition !== Disposition::Pending) {
                $cvReceived = $cvReceived || $existingSource->application_id !== null;
                $applicationCreated = $applicationCreated || $existingSource->application_id !== null;
                $needsReview = $needsReview || $existingSource->review_reason !== null;
                $reviewReason ??= $existingSource->review_reason?->value;

                continue;
            }

            $classified = $this->classifier->classify($attachment, $maxBytes);

            if ($classified->class === AttachmentClass::Cv) {
                $outcome = $this->storeCvFile->store(
                    bytes: $attachment->bytes,
                    originalFilename: $attachment->filename ?? ('attachment-'.$attachment->partId),
                    reportedMime: $attachment->mime ?? 'application/octet-stream',
                    channel: SourceChannel::Email,
                    job: $job,
                    intakeMessageId: $message->id,
                    attachmentKey: $externalId.':'.$attachment->partId,
                    externalReference: $parsed->messageIdHeader,
                );

                $cvReceived = $cvReceived || in_array($outcome->disposition, [Disposition::Stored, Disposition::DuplicateLinked], true);
                $applicationCreated = $applicationCreated || $outcome->createdApplication;

                if ($outcome->reviewReason !== null) {
                    $needsReview = true;
                    $reviewReason ??= $outcome->reviewReason->value;
                }

                continue;
            }

            if ($classified->class === AttachmentClass::Image) {
                // No OCR, no retention: a human decides (D-106, M1-P0-05).
                $this->logExcludedAttachment($message, $attachment, DocumentKind::SupportingDocument, $classified->reason, ReviewReason::ImageAttachment);
                $needsReview = true;
                $reviewReason ??= ReviewReason::ImageAttachment->value;

                continue;
            }

            if ($classified->class === AttachmentClass::Uncertain) {
                // Unknown role: never ignored, always visible (M1-P0-02/05).
                $this->logExcludedAttachment($message, $attachment, DocumentKind::Uncertain, $classified->reason, ReviewReason::UncertainAttachment);
                $needsReview = true;
                $reviewReason ??= ReviewReason::UncertainAttachment->value;

                continue;
            }

            // Other / inline: logged with a reason, not stored, no review item (D-105).
            $this->logExcludedAttachment($message, $attachment, DocumentKind::Other, $classified->reason, null);
        }

        $this->queueNoticeIfAllowed($message, $cvReceived, $parsed->fromAddress, $autoReply);

        if ($needsReview) {
            $this->finish($message, IntakeOutcome::NeedsReview, $reviewReason ?? 'items_need_review');

            app(AiWorkCoordinator::class)->ambiguous($message->id);

            return new ProcessedMessageResult(true, $message->id, $reviewReason, $message->received_at, true, $cvReceived, $applicationCreated);
        }

        if ($cvReceived) {
            $this->finish($message, IntakeOutcome::Processed, IntakeReason::CvProcessed->value);

            return new ProcessedMessageResult(true, $message->id, IntakeReason::CvProcessed->value, $message->received_at, false, true, $applicationCreated);
        }

        // Ignored with reason: minimal record, the stored raw is dropped (D-108).
        $this->dropRaw($message);
        $reason = $parsed->attachments === [] ? IntakeReason::NoAttachments : IntakeReason::NoCvAttachment;
        $this->finish($message, IntakeOutcome::IgnoredWithReason, $reason->value);

        return new ProcessedMessageResult(true, $message->id, $reason->value, $message->received_at);
    }

    private function queueNoticeIfAllowed(IntakeMessage $message, bool $cvReceived, ?string $fromAddress, ?string $autoReplyReason): void
    {
        if (! $cvReceived || $fromAddress === null || $autoReplyReason !== null) {
            return;
        }

        $this->notices->queue($message);
    }

    private function logExcludedAttachment(
        IntakeMessage $message,
        InboundAttachment $attachment,
        DocumentKind $kind,
        string $reason,
        ?ReviewReason $reviewReason,
    ): void {
        DocumentSource::query()->create([
            'intake_message_id' => $message->id,
            'source' => SourceChannel::Email,
            'external_reference' => $message->message_id_header,
            'attachment_key' => $message->external_id.':'.$attachment->partId,
            'attachment_kind' => $kind,
            'disposition' => Disposition::Excluded,
            'original_filename_enc' => $attachment->filename,
            'reported_mime' => $attachment->mime,
            'size' => $attachment->size(),
            'reason' => $reason,
            'review_reason' => $reviewReason,
            'review_opened_at' => $reviewReason === null ? null : now(),
        ]);
    }

    /**
     * Ignored messages keep a minimal record without the body (spec §9.3).
     */
    private function dropRaw(IntakeMessage $message): void
    {
        if ($message->raw_path !== null) {
            $this->store->delete($message->raw_path);
        }

        $message->forceFill(['raw_path' => null])->save();
    }

    private function finish(IntakeMessage $message, IntakeOutcome $outcome, string $reason, ?string $lastError = null): void
    {
        $message->forceFill([
            'outcome' => $outcome,
            'reason' => $reason,
            'visible_at' => now(),
            'last_error' => $lastError,
        ])->save();
    }

    private function maxAttachmentBytes(): int
    {
        return (int) config('recruitment.intake.max_attachment_mb') * 1024 * 1024;
    }
}
