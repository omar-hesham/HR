<?php

namespace App\Infrastructure\Extraction;

use App\Domain\Documents\ReviewReason;
use PhpOffice\PhpWord\Element\AbstractContainer;
use PhpOffice\PhpWord\Element\AbstractElement;
use PhpOffice\PhpWord\Element\Table;
use PhpOffice\PhpWord\Element\Text;
use PhpOffice\PhpWord\Element\TextBreak;
use PhpOffice\PhpWord\IOFactory;
use Throwable;
use ZipArchive;

/**
 * DOCX via PHPWord (M2-P0-03). The file is parsed as XML only; macros are never executed.
 * Paragraphs become lines; table cells are separated by " | " and rows by new lines.
 */
final class DocxTextExtractor
{
    public function extract(string $path): ExtractedText
    {
        $head = (string) file_get_contents($path, false, null, 0, 8);

        // Password-protected OOXML is stored as an OLE container, not a zip.
        if ($head === "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1") {
            throw ExtractionException::permanent(ReviewReason::PasswordProtected, 'encrypted OOXML (OLE container)');
        }

        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            throw ExtractionException::permanent(ReviewReason::CorruptFile, 'not a zip container');
        }

        $hasDocument = $zip->locateName('word/document.xml') !== false;
        $isEncrypted = $zip->locateName('EncryptionInfo') !== false;
        $zip->close();

        if ($isEncrypted) {
            throw ExtractionException::permanent(ReviewReason::PasswordProtected, 'encrypted OOXML');
        }

        if (! $hasDocument) {
            throw ExtractionException::permanent(ReviewReason::CorruptFile, 'word/document.xml missing');
        }

        try {
            $phpWord = IOFactory::load($path, 'Word2007');
        } catch (Throwable $e) {
            throw ExtractionException::permanent(ReviewReason::CorruptFile, 'PHPWord could not read the file', $e);
        }

        $lines = [];

        foreach ($phpWord->getSections() as $section) {
            foreach ($section->getElements() as $element) {
                $this->collect($element, $lines);
            }
        }

        return new ExtractedText(implode("\n", $lines), 'phpword');
    }

    /**
     * @param  list<string>  $lines
     */
    private function collect(AbstractElement $element, array &$lines): void
    {
        if ($element instanceof Table) {
            foreach ($element->getRows() as $row) {
                $cells = [];

                foreach ($row->getCells() as $cell) {
                    $cellLines = [];

                    foreach ($cell->getElements() as $child) {
                        $this->collect($child, $cellLines);
                    }

                    $cells[] = trim(implode(' ', $cellLines));
                }

                $lines[] = implode(' | ', $cells);
            }

            return;
        }

        if ($element instanceof Text) {
            $lines[] = (string) $element->getText();

            return;
        }

        if ($element instanceof TextBreak) {
            $lines[] = '';

            return;
        }

        if ($element instanceof AbstractContainer) {
            $parts = [];
            $isInline = true;

            foreach ($element->getElements() as $child) {
                if ($child instanceof Text) {
                    $parts[] = (string) $child->getText();
                } elseif ($child instanceof TextBreak) {
                    $parts[] = "\n";
                } else {
                    $isInline = false;
                    $nested = [];
                    $this->collect($child, $nested);
                    $parts[] = implode("\n", $nested);
                }
            }

            $lines[] = $isInline ? implode('', $parts) : implode("\n", $parts);
        }
    }
}
