<?php

namespace App\Services\Privacy;

use App\Actions\Privacy\DeleteCandidateData;
use App\Domain\Privacy\DeletionReason;
use App\Infrastructure\Storage\ContentEncrypter;
use App\Models\Application;
use App\Models\Candidate;
use App\Models\DeletionLedgerEntry;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use RuntimeException;

final class DeletionLedger
{
    public function publish(): void
    {
        $target = (string) config('operations.ledger_target');
        if ($target === '') {
            return;
        }
        $key = (string) config('operations.backup_key');
        if ($key === '') {
            throw new RuntimeException('backup_key_missing');
        }
        Cache::lock('deletion-ledger-publication', 60)->block(10, function () use ($target, $key): void {
            File::ensureDirectoryExists(dirname($target), 0700);
            $encrypter = new ContentEncrypter($key);
            $existing = is_file($target) ? json_decode($encrypter->decryptString(File::get($target)), true, 512, JSON_THROW_ON_ERROR) : [];
            $entries = [];
            // Merge published intent as well as current rows: a concurrent DB snapshot or
            // transaction rollback must never remove an already authorized erasure intent.
            foreach ([...$existing, ...DeletionLedgerEntry::query()->get()->toArray()] as $entry) {
                $fingerprint = hash('sha256', json_encode([$entry['opaque_resource_ids'], $entry['source_hmac'], $entry['reason_code'], $entry['deleted_at']], JSON_THROW_ON_ERROR));
                $entries[$fingerprint] = $entry;
            }
            $bytes = $encrypter->encryptString(json_encode(array_values($entries), JSON_THROW_ON_ERROR));
            File::put($target.'.pending', $bytes);
            if (! rename($target.'.pending', $target)) {
                throw new RuntimeException('ledger_publish_failed');
            }
        });
    }

    /** @param list<array<string,mixed>> $entries */
    public function replay(array $entries): void
    {
        foreach ($entries as $entry) {
            foreach ($entry['opaque_resource_ids'] as $reference) {
                [$type, $uuid] = explode(':', $reference, 2);
                if ($type === 'candidate') {
                    $person = Candidate::query()->where('uuid', $uuid)->first();
                    if ($person !== null) {
                        app(DeleteCandidateData::class)->candidate($person->id, DeletionReason::RestoreLedger);
                    }
                } elseif ($type === 'application') {
                    $application = Application::query()->where('uuid', $uuid)->first();
                    if ($application !== null) {
                        app(DeleteCandidateData::class)->application($application->id, DeletionReason::RestoreLedger);
                    }
                }
            }
            // Preserve the newest ledger in the restored environment too.
            // Compare the id list in PHP: a JSON column never equals a string on MySQL, which would duplicate rows on every restore.
            $exists = DeletionLedgerEntry::query()->where('reason_code', $entry['reason_code'])->get()
                ->contains(fn (DeletionLedgerEntry $row) => $row->opaque_resource_ids === $entry['opaque_resource_ids']);
            if (! $exists) {
                DeletionLedgerEntry::query()->create(['opaque_resource_ids' => $entry['opaque_resource_ids'], 'reason_code' => $entry['reason_code'], 'source_hmac' => $entry['source_hmac'], 'deleted_at' => $entry['deleted_at']]);
            }
        }
        app(ErasureFiles::class)->purge();
    }
}
