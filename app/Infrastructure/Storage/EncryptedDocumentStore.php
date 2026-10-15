<?php

namespace App\Infrastructure\Storage;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Stores CV files encrypted on the private disk, outside the web root (ARC-P0-04).
 * Paths contain identifiers only, never a person's name.
 */
final class EncryptedDocumentStore
{
    public function __construct(private readonly ContentEncrypter $encrypter) {}

    public function put(string $candidateUuid, string $documentUuid, string $plainBytes): string
    {
        $path = sprintf('%s/%s/documents/%s.bin', config('recruitment.storage.documents_prefix'), $candidateUuid, $documentUuid);

        $this->disk()->put($path, $this->encrypter->encryptString($plainBytes));

        return $path;
    }

    public function get(string $path): string
    {
        return $this->encrypter->decryptString((string) $this->disk()->get($path));
    }

    /**
     * Decrypt to a private temp file for external tools. Caller must delete it (see deleteTemp()).
     */
    public function toTempFile(string $path, string $extension): string
    {
        $relative = sprintf('%s/%s.%s', config('recruitment.storage.tmp_prefix'), Str::uuid()->toString(), $extension);
        $this->disk()->put($relative, $this->get($path));

        return $this->disk()->path($relative);
    }

    public function deleteTemp(string $absolutePath): void
    {
        if (is_file($absolutePath)) {
            @unlink($absolutePath);
        }
    }

    public function exists(string $path): bool
    {
        return $this->disk()->exists($path);
    }

    public function disk(): Filesystem
    {
        return Storage::disk(config('recruitment.storage.disk'));
    }
}
