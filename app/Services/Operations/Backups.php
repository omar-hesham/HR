<?php

namespace App\Services\Operations;

use App\Infrastructure\Storage\ContentEncrypter;
use App\Models\BackupRun;
use App\Services\Privacy\DeletionLedger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;
use ZipArchive;

final class Backups
{
    public function create(): BackupRun
    {
        $archiveOpen = false;
        $zip = new ZipArchive;
        $run = BackupRun::query()->create(['started_at' => now(), 'status' => 'running']);
        try {
            $target = (string) config('operations.backup_target');
            if ($target === '' || (string) config('operations.backup_key') === '') {
                throw new RuntimeException('backup_configuration_missing');
            }
            app(DeletionLedger::class)->publish();
            File::ensureDirectoryExists($target, 0700);
            $root = storage_path('app/private/ops/backup-'.Str::uuid());
            File::ensureDirectoryExists($root, 0700);
            $zipPath = $root.'/payload.zip';
            if ($zip->open($zipPath, ZipArchive::CREATE) !== true) {
                throw new RuntimeException('backup_archive_failed');
            }
            $archiveOpen = true;
            $snapshot = DB::transaction(fn (): array => app(DatabaseSnapshot::class)->capture());
            $zip->addFromString('database.json', json_encode($snapshot, JSON_THROW_ON_ERROR));
            $hashes = [];
            foreach (['private', 'review_exports'] as $diskName) {
                $disk = Storage::disk($diskName);
                foreach ($disk->allFiles() as $path) {
                    if (str_starts_with($path, 'ops/') || str_starts_with($path, 'tmp/')) {
                        continue;
                    }
                    $bytes = $disk->get($path);
                    $entry = 'files/'.$diskName.'/'.$path;
                    $zip->addFromString($entry, $bytes);
                    $hashes[$entry] = hash('sha256', $bytes);
                }
            }
            ksort($hashes);
            $manifest = ['format' => 1, 'engine' => DB::connection()->getDriverName(), 'database_hash' => app(DatabaseSnapshot::class)->hash($snapshot), 'files' => $hashes, 'migrations_hash' => $this->migrationsHash(), 'created_at' => now()->toIso8601String()];
            $manifestBytes = json_encode($manifest, JSON_THROW_ON_ERROR);
            $zip->addFromString('manifest.json', $manifestBytes);
            if (! $zip->close()) {
                throw new RuntimeException('backup_archive_failed');
            }
            $archiveOpen = false;
            $location = (string) Str::uuid().'.backup';
            $encrypted = (new ContentEncrypter((string) config('operations.backup_key')))->encryptString(File::get($zipPath));
            File::put($target.'/'.$location, $encrypted);
            // Only our own plaintext staging file is removed; no existing backup is rotated.
            unlink($zipPath);
            $run->forceFill(['status' => 'successful', 'finished_at' => now(), 'manifest_hash' => hash('sha256', $manifestBytes), 'location_ref' => $location])->save();

            return $run;
        } catch (Throwable $exception) {
            $run->forceFill(['status' => 'failed', 'finished_at' => now()])->save();
            activity('operations')->event('backup.failed')->log('backup.failed');
            throw new RuntimeException('backup_failed', previous: $exception);
        } finally {
            if ($archiveOpen) {
                $zip->close();
            }
            if (isset($zipPath) && is_file($zipPath)) {
                unlink($zipPath);
            }
        }
    }

    public function migrationsHash(): string
    {
        $hashes = [];
        foreach (File::files(database_path('migrations')) as $file) {
            $hashes[$file->getFilename()] = hash_file('sha256', $file->getPathname());
        }
        ksort($hashes);

        return hash('sha256', json_encode($hashes, JSON_THROW_ON_ERROR));
    }

    /** @return array<string,mixed> */
    public function health(): array
    {
        $last = BackupRun::query()->where('status', 'successful')->latest('finished_at')->first();

        return ['fresh' => $last !== null && $last->getAttribute('finished_at')->gte(now()->subHours(24)), 'last_success_at' => $last?->getAttribute('finished_at'), 'restore_test_at' => BackupRun::query()->max('restore_test_at'), 'configured' => (string) config('operations.backup_target') !== '' && (string) config('operations.backup_key') !== ''];
    }
}
