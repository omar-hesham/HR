<?php

namespace App\Services\Operations;

use App\Infrastructure\Storage\ContentEncrypter;
use App\Models\BackupRun;
use App\Models\DeletionLedgerEntry;
use App\Services\Privacy\DeletionLedger;
use App\Services\Settings\SettingsRepository;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

/** Never opens the live database for restore writes. No queue worker runs in the drill. */
final class RestoreVerification
{
    public function verify(BackupRun $run, ?string $externalLedger = null): string
    {
        if ($run->status !== 'successful' || ! preg_match('/^[a-f0-9-]+\.backup$/D', (string) $run->getAttribute('location_ref'))) {
            throw new RuntimeException('invalid_backup');
        }
        $latestLedger = DeletionLedgerEntry::query()->get()->toArray();
        $ledgerPath = $externalLedger ?? (string) config('operations.ledger_target');
        if ($ledgerPath !== '') {
            if (! is_file($ledgerPath)) {
                throw new RuntimeException('current_ledger_missing');
            }
            $published = json_decode((new ContentEncrypter((string) config('operations.backup_key')))->decryptString(File::get($ledgerPath)), true, 512, JSON_THROW_ON_ERROR);
            $latestLedger = [...$latestLedger, ...$published];
        } elseif (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('current_ledger_required');
        }
        $root = storage_path('app/private/ops/restore-'.Str::uuid());
        File::ensureDirectoryExists($root, 0700);
        $zipPath = $root.'/payload.zip';
        $archiveOpen = false;
        $zip = new ZipArchive;
        try {
            $bytes = File::get((string) config('operations.backup_target').'/'.$run->getAttribute('location_ref'));
            $zipPath = $root.'/payload.zip';
            File::put($zipPath, (new ContentEncrypter((string) config('operations.backup_key')))->decryptString($bytes));
            if ($zip->open($zipPath) !== true) {
                throw new RuntimeException('restore_archive_invalid');
            }
            $archiveOpen = true;
            $manifestBytes = $zip->getFromName('manifest.json');
            if ($manifestBytes === false || ! hash_equals((string) $run->getAttribute('manifest_hash'), hash('sha256', $manifestBytes))) {
                throw new RuntimeException('restore_manifest_mismatch');
            }
            $manifest = json_decode($manifestBytes, true, 512, JSON_THROW_ON_ERROR);
            if ($manifest['migrations_hash'] !== app(Backups::class)->migrationsHash()) {
                throw new RuntimeException('restore_requires_matching_release');
            }
            $snapshot = json_decode((string) $zip->getFromName('database.json'), true, 512, JSON_THROW_ON_ERROR);
            if ($manifest['database_hash'] !== app(DatabaseSnapshot::class)->hash($snapshot)) {
                throw new RuntimeException('restore_database_mismatch');
            }
            $originalConnection = DB::getDefaultConnection();
            $originalDisks = config('filesystems.disks');
            $originalAdapters = ['private' => Storage::disk('private'), 'review_exports' => Storage::disk('review_exports')];
            $originalLedgerTarget = config('operations.ledger_target');
            $database = $root.'/database.sqlite';
            touch($database);
            $connection = ['driver' => 'sqlite', 'database' => $database, 'foreign_key_constraints' => true];
            if (! app()->environment(['local', 'testing'])) {
                $connection = config('operations.restore_connection');
                $liveDatabase = config('database.connections.'.$originalConnection.'.database');
                if ($connection['driver'] !== $manifest['engine'] || ! str_starts_with($connection['database'], 'recruit_restore_') || $connection['database'] === $liveDatabase) {
                    throw new RuntimeException('restore_isolation_required');
                }
            }
            config(['database.connections.restore_drill' => $connection, 'operations.ledger_target' => '']);
            DB::purge('restore_drill');
            try {
                DB::setDefaultConnection('restore_drill');
                foreach (['private', 'review_exports'] as $disk) {
                    config(['filesystems.disks.'.$disk => ['driver' => 'local', 'root' => $root.'/'.$disk, 'throw' => true, 'serve' => false]]);
                    Storage::forgetDisk($disk);
                }
                if (! app()->environment(['local', 'testing']) && Schema::getTableListing($connection['database'], false) !== []) {
                    throw new RuntimeException('restore_target_not_empty');
                }
                Artisan::call('migrate', ['--database' => 'restore_drill', '--force' => true]);
                app(DatabaseSnapshot::class)->load($snapshot);
                if (app(DatabaseSnapshot::class)->hash(app(DatabaseSnapshot::class)->capture()) !== $manifest['database_hash']) {
                    throw new RuntimeException('restore_rows_mismatch');
                }
                foreach ($manifest['files'] as $entry => $expected) {
                    if (! preg_match('#^files/(private|review_exports)/(.+)$#D', $entry, $match) || str_contains($match[2], '..') || str_contains($match[2], '\\')) {
                        throw new RuntimeException('restore_path_invalid');
                    }
                    $content = $zip->getFromName($entry);
                    if ($content === false || ! hash_equals($expected, hash('sha256', $content))) {
                        throw new RuntimeException('restore_file_mismatch');
                    }
                    Storage::disk($match[1])->put($match[2], $content);
                    if (hash('sha256', Storage::disk($match[1])->get($match[2])) !== $expected) {
                        throw new RuntimeException('restore_file_write_mismatch');
                    }
                }
                $zip->close();
                $archiveOpen = false;
                unlink($zipPath);
                app(SettingsRepository::class)->flush();
                app(DeletionLedger::class)->replay($latestLedger);
                File::put($root.'/verification.json', json_encode(['database_match' => true, 'audit_match' => true, 'files_verified' => count($manifest['files']), 'ledger_replayed' => count($latestLedger), 'engine' => $connection['driver'], 'production_engine_acceptance' => $connection['driver'] === $manifest['engine'] && ! app()->environment(['local', 'testing'])], JSON_THROW_ON_ERROR));
                File::put($root.'/OPEN', 'ledger_replayed_before_access');
            } finally {
                DB::disconnect('restore_drill');
                DB::setDefaultConnection($originalConnection);
                config(['filesystems.disks' => $originalDisks, 'operations.ledger_target' => $originalLedgerTarget]);
                foreach ($originalAdapters as $disk => $adapter) {
                    Storage::set($disk, $adapter);
                }
                app(SettingsRepository::class)->flush();
            }
            $run->forceFill(['restore_test_at' => now(), 'restore_engine' => $connection['driver']])->save();
            activity('operations')->event('backup.restore_verified')->withProperties(['backup_run_id' => $run->id])->log('backup.restore_verified');

            return $root;
        } finally {
            if ($archiveOpen) {
                $zip->close();
            }
            if (is_file($zipPath)) {
                unlink($zipPath);
            }
        }
    }
}
