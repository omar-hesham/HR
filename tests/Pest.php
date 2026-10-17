<?php

use App\Domain\Access\Role;
use App\Infrastructure\Extraction\Binaries;
use App\Jobs\PollMailbox;
use App\Models\Mailbox;
use App\Models\RecruitmentJob;
use App\Models\User;
use App\Services\Intake\MailboxDriverFactory;
use App\Services\Intake\ProcessInboundMessage;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

pest()->extend(TestCase::class)->beforeEach(fn () => Http::preventStrayRequests())->in('Unit');

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function () {
        Http::preventStrayRequests();
        test()->seed(RolesAndPermissionsSeeder::class);
    })
    ->in('Feature');

/**
 * A synthetic user with one role, MFA enrolled, optionally assigned to jobs.
 *
 * @param  list<RecruitmentJob>  $jobs
 */
function userWithRole(Role $role, array $jobs = [], bool $mfa = true): User
{
    $factory = User::factory();

    if ($mfa) {
        $factory = $factory->withMfa();
    }

    $user = $factory->role($role)->create();

    foreach ($jobs as $job) {
        $user->assignedJobs()->attach($job->id);
    }

    return $user->fresh() ?? $user;
}

function cvFixture(string $name): string
{
    return dirname(__DIR__).'/tests/Fixtures/cvs/'.$name;
}

/**
 * An UploadedFile for a synthetic fixture (test mode: no real HTTP upload).
 */
function fixtureUpload(string $name, ?string $clientName = null): UploadedFile
{
    $copy = sys_get_temp_dir().'/recruit-test-'.bin2hex(random_bytes(6)).'-'.$name;
    copy(cvFixture($name), $copy);

    return new UploadedFile($copy, $clientName ?? $name, null, null, true);
}

function requirePdftotext(): void
{
    if (Binaries::pdftotext() === null) {
        test()->markTestSkipped('pdftotext (poppler/xpdf) is not installed; set PDFTOTEXT_PATH to run this test.');
    }
}

/*
 * Milestone 2 mail-intake helpers (fake driver only; no real mailbox, brief §8).
 */

function emlFixture(string $name): string
{
    return dirname(__DIR__).'/tests/Fixtures/eml/'.$name.'.eml';
}

/**
 * A fake-provider mailbox with its own .eml folder and a start pointer in the past.
 *
 * @param  array<string, mixed>  $attributes
 */
function fakeMailbox(array $attributes = []): Mailbox
{
    $folder = sys_get_temp_dir().'/recruit-mailbox-'.bin2hex(random_bytes(6));

    mkdir($folder, 0775, true);

    return Mailbox::query()->create(array_merge([
        'provider' => 'fake',
        'address' => 'recruitment-'.bin2hex(random_bytes(3)).'@example.test',
        'auth_type' => 'password',
        'credential_ref' => 'default',
        'host' => $folder,
        'start_at' => '2026-01-01 00:00:00',
        'active' => true,
    ], $attributes));
}

/**
 * Copy a committed fixture (or write runtime content) into the mailbox folder.
 */
function pushEml(Mailbox $mailbox, string $fixture, ?string $content = null): string
{
    $externalId = str_replace('.eml', '', basename($fixture));
    file_put_contents(rtrim((string) $mailbox->host, '/\\').'/'.$externalId.'.eml', $content ?? (string) file_get_contents(emlFixture($fixture)));

    return $externalId;
}

/**
 * Runs the poll job synchronously against a fake mailbox.
 */
function pollMailbox(Mailbox $mailbox): void
{
    (new PollMailbox($mailbox->id))
        ->handle(app(MailboxDriverFactory::class), app(ProcessInboundMessage::class));
}

/**
 * Minimal runtime .eml builder for tests (EICAR is built at run time, never committed).
 *
 * @param  list<array{filename: string, bytes: string, type: string}>  $attachments
 */
function buildEml(string $from, string $messageId, string $subject, array $attachments = [], string $date = 'Thu, 01 Oct 2026 12:00:00 +0000'): string
{
    $lines = [
        "From: {$from}",
        'To: recruitment@example.test',
        "Subject: {$subject}",
        "Date: {$date}",
        "Message-ID: {$messageId}",
    ];

    if ($attachments === []) {
        $lines[] = 'Content-Type: text/plain; charset=UTF-8';

        return implode("\r\n", $lines)."\r\n\r\nBody\r\n";
    }

    $boundary = '=_test'.bin2hex(random_bytes(4));
    $lines[] = "Content-Type: multipart/mixed; boundary=\"{$boundary}\"";
    $parts = ["--{$boundary}", 'Content-Type: text/plain; charset=UTF-8', '', 'Body', ''];

    foreach ($attachments as $attachment) {
        $parts = [...$parts, "--{$boundary}", "Content-Type: {$attachment['type']}; name=\"{$attachment['filename']}\"", "Content-Disposition: attachment; filename=\"{$attachment['filename']}\"", 'Content-Transfer-Encoding: base64', '', chunk_split(base64_encode($attachment['bytes']), 76, "\r\n")];
    }

    $parts[] = "--{$boundary}--";

    return implode("\r\n", $lines)."\r\n".implode("\r\n", $parts);
}

/**
 * The EICAR test string, assembled at run time so the signature is never committed whole.
 */
function eicarString(): string
{
    return 'X5O!P%@AP[4\\PZX54(P^)7CC)7'.'}$EICAR-STANDARD-'.'ANTIVIRUS-TEST-FILE!$H+H*';
}
