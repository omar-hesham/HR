<?php

namespace App\Infrastructure\Extraction;

use App\Domain\Documents\ReviewReason;
use Spatie\PdfToText\Exceptions\CouldNotExtractText;
use Spatie\PdfToText\Pdf;
use Symfony\Component\Process\Exception\ProcessTimedOutException;

/**
 * Text PDFs via poppler/xpdf pdftotext (M2-P0-03). Reading order mode (no -layout, D-64).
 * No OCR: image-only PDFs come back (almost) empty and the quality gate routes them to review.
 */
final class PdfTextExtractor
{
    public function extract(string $path): ExtractedText
    {
        $binary = Binaries::pdftotext();

        if ($binary === null) {
            throw ExtractionException::transient('pdftotext binary not found');
        }

        $head = (string) file_get_contents($path, false, null, 0, 1024);

        if (! str_starts_with(ltrim($head), '%PDF-')) {
            throw ExtractionException::permanent(ReviewReason::CorruptFile, 'missing PDF header');
        }

        if ($this->isEncrypted($path)) {
            throw ExtractionException::permanent(ReviewReason::PasswordProtected, 'PDF is encrypted');
        }

        try {
            $text = (new Pdf($binary))
                ->setPdf($path)
                // spatie/pdf-to-text expects "flag value" strings.
                ->setOptions(['enc UTF-8', 'eol unix'])
                ->setTimeout((int) config('recruitment.extraction.pdftotext_timeout', 60))
                ->text();
        } catch (ProcessTimedOutException $e) {
            throw ExtractionException::transient('pdftotext timed out', $e);
        } catch (CouldNotExtractText $e) {
            $error = strtolower($e->getProcess()->getErrorOutput());

            if (str_contains($error, 'password') || str_contains($error, 'encrypt')) {
                throw ExtractionException::permanent(ReviewReason::PasswordProtected, 'pdftotext: password', $e);
            }

            throw ExtractionException::permanent(ReviewReason::CorruptFile, 'pdftotext exit '.$e->getProcess()->getExitCode(), $e);
        }

        $pages = substr_count($text, "\f");

        return new ExtractedText($text, 'pdftotext', $pages > 0 ? $pages : null);
    }

    /**
     * An /Encrypt entry in the trailer means a security handler; we treat any
     * encrypted PDF as needing review rather than trying owner-password bypasses.
     */
    private function isEncrypted(string $path): bool
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return false;
        }

        $size = (int) filesize($path);
        $tail = '';

        if ($size > 0) {
            fseek($handle, max(0, $size - 65536));
            $tail = (string) fread($handle, 65536);
        }

        fclose($handle);

        return preg_match('#/Encrypt\s+\d+\s+\d+\s+R#', $tail) === 1
            || preg_match('#/Encrypt\s*<<#', $tail) === 1;
    }
}
