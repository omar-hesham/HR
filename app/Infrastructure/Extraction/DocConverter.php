<?php

namespace App\Infrastructure\Extraction;

use App\Domain\Documents\ReviewReason;
use Illuminate\Support\Str;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Legacy .doc → .docx with LibreOffice headless, behind DOC_CONVERSION_ENABLED (D-65).
 * Runs with a throw-away user profile so no shared state, macros or network settings
 * leak between conversions. Network isolation is enforced at the OS level in production.
 */
final class DocConverter
{
    public function isAvailable(): bool
    {
        return (bool) config('recruitment.extraction.doc_enabled') && Binaries::libreoffice() !== null;
    }

    /**
     * @return string absolute path of the converted .docx (caller deletes the directory)
     */
    public function toDocx(string $docPath, string $workDir): string
    {
        $binary = Binaries::libreoffice();

        if (! config('recruitment.extraction.doc_enabled') || $binary === null) {
            throw ExtractionException::permanent(ReviewReason::DocConversionDisabled, 'DOC conversion disabled');
        }

        $profile = $workDir.DIRECTORY_SEPARATOR.'profile-'.Str::random(8);
        @mkdir($profile, 0700, true);
        $profileUrl = 'file:///'.ltrim(str_replace('\\', '/', $profile), '/');

        $process = new Process([
            $binary,
            '-env:UserInstallation='.$profileUrl,
            '--headless',
            '--norestore',
            '--nolockcheck',
            '--convert-to',
            'docx:MS Word 2007 XML',
            '--outdir',
            $workDir,
            $docPath,
        ]);
        $process->setTimeout((int) config('recruitment.extraction.libreoffice_timeout', 60));

        try {
            $process->run();
        } catch (ProcessTimedOutException $e) {
            throw ExtractionException::transient('LibreOffice timed out', $e);
        }

        $output = $workDir.DIRECTORY_SEPARATOR.pathinfo($docPath, PATHINFO_FILENAME).'.docx';

        if (! $process->isSuccessful() || ! is_file($output)) {
            throw ExtractionException::permanent(ReviewReason::CorruptFile, 'LibreOffice conversion failed');
        }

        return $output;
    }
}
