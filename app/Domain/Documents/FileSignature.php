<?php

namespace App\Domain\Documents;

/**
 * Magic-byte check so the claimed extension matches the real content (M2-P0-02).
 */
final class FileSignature
{
    /**
     * Real type of the bytes: 'pdf', 'docx', 'doc' or null when unknown.
     */
    public static function detect(string $bytes): ?string
    {
        if (str_starts_with(ltrim(substr($bytes, 0, 1024)), '%PDF-')) {
            return 'pdf';
        }

        if (str_starts_with($bytes, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1")) {
            return 'doc';
        }

        if (str_starts_with($bytes, "PK\x03\x04") && str_contains($bytes, 'word/document.xml')) {
            return 'docx';
        }

        // Encrypted OOXML files are OLE containers with an EncryptionInfo stream.
        return null;
    }

    public static function matchesExtension(string $bytes, string $extension): bool
    {
        $detected = self::detect($bytes);

        if ($extension === 'docx' && $detected === 'doc' && str_contains($bytes, "E\0n\0c\0r\0y\0p\0t\0i\0o\0n\0I\0n\0f\0o")) {
            // Password-protected DOCX: valid upload, extraction will route it to review.
            return true;
        }

        return $detected === $extension;
    }
}
