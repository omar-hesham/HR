<?php

/**
 * D-117: the dependency-free clamd INSTREAM client — wire format and
 * ClamAV verdict parsing (no socket needed; the real daemon runs in production).
 */

use App\Services\Malware\ClamAvScanner;
use App\Services\Malware\ClamdClient;
use App\Services\Malware\ClamdConnection;
use App\Services\Malware\MalwareScanner;

it('builds the exact INSTREAM wire format', function () {
    $bytes = 'abcde';
    $payload = ClamdClient::buildInstreamPayload($bytes, 2);

    expect($payload)->toBe("zINSTREAM\0".pack('N', 2).'ab'.pack('N', 2).'cd'.pack('N', 1).'e'.pack('N', 0));
});

it('splits large inputs into chunks and terminates with a zero-length chunk', function () {
    $bytes = str_repeat('A', 10);
    $payload = ClamdClient::buildInstreamPayload($bytes, 4);

    // "zINSTREAM\0" (10) + [4-byte size + 4 bytes] x 2 + [4-byte size + 2 bytes] + terminator (4).
    expect(strlen($payload))->toBe(10 + 8 + 8 + 6 + 4)
        ->and(substr($payload, -4))->toBe(pack('N', 0));
});

it('maps clamd replies to verdicts', function (string $reply, bool $clean, ?string $signature) {
    $scanner = new ClamAvScanner(new StubClamdClient($reply));
    $verdict = $scanner->scan('anything', 'file.bin');

    expect($verdict->clean)->toBe($clean)
        ->and($verdict->skipped)->toBeFalse()
        ->and($verdict->signature)->toBe($signature);
})->with([
    'clean' => ["stream: OK\0", true, null],
    'eicar' => ["stream: Eicar-Test-Signature FOUND\0", false, 'Eicar-Test-Signature'],
]);
it('fails loudly on an unexpected clamd reply', function (string $reply) {
    $scanner = new ClamAvScanner(new StubClamdClient($reply));

    $scanner->scan('anything', 'file.bin');
})->with(['COMMAND READ TIMEOUT', "stream: NOT OK\0", "stream: Eicar FOUND trailing\0"])->throws(RuntimeException::class);

it('fails closed for an unknown scanner or a disabled production scanner', function (string $environment, ?string $driver) {
    app()->instance('env', $environment);
    config(['recruitment.malware.driver' => $driver]);
    app()->forgetInstance(MalwareScanner::class);
    app(MalwareScanner::class);
})->with([
    ['testing', 'clamav-typo'],
    ['production', null],
])->throws(RuntimeException::class);

final class StubClamdClient implements ClamdConnection
{
    public function __construct(private readonly string $reply) {}

    public function instream(string $bytes): string
    {
        return $this->reply;
    }
}
