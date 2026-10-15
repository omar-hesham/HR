<?php

namespace App\Infrastructure\Extraction;

use Symfony\Component\Process\ExecutableFinder;

/**
 * Resolves external binaries (pdftotext, LibreOffice) from config or PATH.
 */
final class Binaries
{
    public static function find(string $configured): ?string
    {
        if ($configured === '') {
            return null;
        }

        if (str_contains($configured, '/') || str_contains($configured, '\\')) {
            return is_file($configured) ? $configured : null;
        }

        return (new ExecutableFinder)->find($configured);
    }

    public static function pdftotext(): ?string
    {
        return self::find((string) config('recruitment.extraction.pdftotext_path'));
    }

    public static function libreoffice(): ?string
    {
        return self::find((string) config('recruitment.extraction.libreoffice_path'));
    }
}
