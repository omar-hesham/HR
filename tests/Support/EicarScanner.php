<?php

namespace Tests\Support;

use App\Services\Malware\MalwareScanner;
use App\Services\Malware\ScanVerdict;

/**
 * Test scanner: flags any input containing the (runtime-assembled) EICAR test
 * string; everything else is clean. Replaces the null scanner in the container.
 */
final class EicarScanner implements MalwareScanner
{
    public function scan(string $bytes, string $filename): ScanVerdict
    {
        $eicar = \eicarString();

        return str_contains($bytes, $eicar) ? ScanVerdict::infected('Eicar-Test-Signature') : ScanVerdict::clean();
    }
}
