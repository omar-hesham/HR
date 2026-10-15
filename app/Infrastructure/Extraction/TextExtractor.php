<?php

namespace App\Infrastructure\Extraction;

use App\Domain\Documents\ReviewReason;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Picks the extractor for a decrypted temp file by its verified extension.
 */
final class TextExtractor
{
    public function __construct(
        private readonly PdfTextExtractor $pdf,
        private readonly DocxTextExtractor $docx,
        private readonly DocConverter $doc,
    ) {}

    public function extract(string $path, string $extension): ExtractedText
    {
        return match ($extension) {
            'pdf' => $this->pdf->extract($path),
            'docx' => $this->docx->extract($path),
            'doc' => $this->extractDoc($path),
            default => throw ExtractionException::permanent(ReviewReason::InvalidType, 'unsupported extension'),
        };
    }

    private function extractDoc(string $path): ExtractedText
    {
        if (! $this->doc->isAvailable()) {
            throw ExtractionException::permanent(ReviewReason::DocConversionDisabled, 'DOC conversion disabled');
        }

        $workDir = dirname($path).DIRECTORY_SEPARATOR.'doc-'.Str::random(10);
        File::ensureDirectoryExists($workDir, 0700);

        try {
            $result = $this->docx->extract($this->doc->toDocx($path, $workDir));

            return new ExtractedText($result->text, 'libreoffice+phpword', $result->pageCount);
        } finally {
            File::deleteDirectory($workDir);
        }
    }
}
