<?php

namespace App\Services\Privacy;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class ErasureFiles
{
    public function queue(string $disk, ?string $path): void
    {
        if ($path !== null && $path !== '') {
            DB::table('erasure_files')->updateOrInsert(['disk' => $disk, 'path' => $path], ['purged_at' => null, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function purge(): void
    {
        foreach (DB::table('erasure_files')->whereNull('purged_at')->orderBy('id')->lazy() as $file) {
            $disk = Storage::disk($file->disk);
            if ($disk->exists($file->path) && ! $disk->delete($file->path)) {
                throw new RuntimeException('erasure_storage_failed');
            }
            DB::table('erasure_files')->where('id', $file->id)->update(['purged_at' => now(), 'updated_at' => now()]);
        }
    }
}
