<?php

use App\Domain\Access\Role;
use App\Infrastructure\Extraction\Binaries;
use App\Models\RecruitmentJob;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

pest()->extend(TestCase::class)->in('Unit');

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(fn () => test()->seed(RolesAndPermissionsSeeder::class))
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
