<?php

namespace App\Actions\Documents;

use App\Domain\Documents\Disposition;
use App\Domain\Documents\ReviewReason;
use App\Domain\Documents\SourceChannel;
use App\Models\Document;
use App\Models\RecruitmentJob;
use App\Models\User;
use App\Services\Settings\SettingsRepository;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Manual upload (source = upload), single or bulk (M2-P0-01/02, M3-P0-01 hash part).
 *
 * Gate, batch limits and the batch activity log live here; the per-file work
 * (accounting row first, validation, malware scan, duplicate detection, encrypted
 * storage, provisional candidate + application, queued extraction) is shared with
 * mail intake through StoreCvFile.
 */
final class UploadDocuments
{
    public function __construct(
        private readonly StoreCvFile $storeCvFile,
        private readonly SettingsRepository $settings,
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
            $bytes = $file->isValid() ? file_get_contents((string) $file->getRealPath()) : false;

            $outcome = $this->storeCvFile->store(
                bytes: $bytes === false ? '' : $bytes,
                originalFilename: $file->getClientOriginalName(),
                reportedMime: (string) $file->getClientMimeType(),
                channel: SourceChannel::Upload,
                actor: $actor,
                job: $job,
                attachmentKey: $result->batch.':'.$index,
                uploadBatch: $result->batch,
                receiptError: $bytes === false ? ReviewReason::CorruptFile : null,
            );

            $result->add($index, $outcome->sourceId, $outcome->disposition, $outcome->reviewReason);
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
}
