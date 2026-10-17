<?php

use App\Domain\Access\Permission;
use App\Domain\Access\Role;
use App\Filament\Resources\Applications\Pages\ListApplications;
use App\Infrastructure\Storage\ContentEncrypter;
use App\Models\ReviewExport;
use App\Services\Review\ExportApplications;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Spatie\Activitylog\Models\Activity;

require_once __DIR__.'/../../Support/M4.php';

beforeEach(function () {
    Storage::fake('review_exports');
    Mail::fake();
});

function m4ReadWorkbook(ReviewExport $artifact): Spreadsheet
{
    $plain = app(ContentEncrypter::class)->decryptString(Storage::disk('review_exports')->get($artifact->path));
    $directory = storage_path('app/private/tmp');
    if (! is_dir($directory)) {
        mkdir($directory, 0775, true);
    }
    $path = $directory.'/m4a-'.Str::uuid().'.xlsx';
    file_put_contents($path, $plain);

    return IOFactory::load($path);
}

it('exports a round-trip RTL workbook with minimal columns and exact phone text', function (string $phone) {
    $f = m4Application(null, ['name' => '=1+1', 'phone' => $phone, 'email' => 'synthetic@example.test']);
    $owner = userWithRole(Role::Owner);
    $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => now()->timestamp]);
    $artifact = app(ExportApplications::class)->create($owner, $f['job'], 'xlsx', 'Synthetic weekly shortlist');
    $encrypted = Storage::disk('review_exports')->get($artifact->path);
    expect($encrypted)->not->toContain('synthetic@example.test')->not->toStartWith('PK');
    $sheet = m4ReadWorkbook($artifact)->getActiveSheet();
    expect($sheet->getRightToLeft())->toBeTrue()
        ->and($sheet->getCell('A2')->getValue())->toBe('=1+1')->and($sheet->getCell('A2')->getDataType())->toBe(DataType::TYPE_STRING)
        ->and($sheet->getStyle('A2')->getQuotePrefix())->toBeTrue()
        ->and($sheet->getCell('B2')->getValue())->toBe($phone)->and($sheet->getCell('B2')->getDataType())->toBe(DataType::TYPE_STRING)
        ->and($sheet->getCell('G2')->getValue())->toContain(__('review.outcomes.met', [], 'ar'))->not->toContain('PLC programming')->not->toContain('Samir Hassan')
        ->and($sheet->getCell('I2')->getValue())->toBe((string) $f['version']->version)
        ->and($sheet->getHighestColumn())->toBe('J');
    $this->get(route('review-exports.download', $artifact->id))->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    expect(Activity::query()->where('event', 'export.created')->count())->toBe(1)
        ->and(Activity::query()->where('event', 'export.downloaded')->sole()->causer_id)->toBe($owner->id);
    Mail::assertNothingSent();
})->with(['+201001234567', '01001234567']);

it('sanitizes every formula prefix including whitespace and writes a BOM CSV', function (string $value) {
    $f = m4Application(null, ['name' => $value, 'phone' => '+201001234567']);
    $manager = userWithRole(Role::HiringManager, [$f['job']]);
    $this->actingAs($manager);
    $artifact = app(ExportApplications::class)->create($manager, $f['job'], 'csv', 'Weekly contact list');
    $plain = app(ExportApplications::class)->download($manager, $artifact);
    expect($plain)->toStartWith("\xEF\xBB\xBF");
    $lines = explode("\r\n", substr($plain, 3));
    $row = str_getcsv($lines[1], ',', '"', '');
    expect($row[0])->toBe("'".$value)->and($row[1])->toBe("'+201001234567");
})->with(['=SUM(1,2)', '+1+2', '-1+2', '@formula', " \t=1+1"]);

it('omits identity columns under either name blind flag', function (string $flag, string $format) {
    $f = m4Application(null, ['name' => 'PrivateSyntheticName', 'phone' => '+201001234567', 'email' => 'private@example.test']);
    $manager = userWithRole(Role::HiringManager, [$f['job']]);
    ($flag === 'user' ? $manager : $f['job'])->update(['name_blind_review' => true]);
    $this->actingAs($manager);
    $artifact = app(ExportApplications::class)->create($manager, $f['job']->fresh(), $format, 'Blind review');
    $plain = app(ExportApplications::class)->download($manager, $artifact);
    expect($artifact->identified)->toBeFalse();
    if ($format === 'csv') {
        expect($plain)->not->toContain('PrivateSyntheticName')->not->toContain('private@example.test')->not->toContain('+201001234567');
        expect(str_getcsv(explode("\r\n", substr($plain, 3))[0], ',', '"', ''))->toHaveCount(7);
    } else {
        $sheet = m4ReadWorkbook($artifact)->getActiveSheet();
        expect($sheet->getHighestColumn())->toBe('G')
            ->and(json_encode($sheet->toArray()))->not->toContain('PrivateSyntheticName')->not->toContain('private@example.test')->not->toContain('+201001234567');
    }
})->with([['user', 'csv'], ['job', 'csv'], ['user', 'xlsx'], ['job', 'xlsx']]);

it('requires recent password confirmation for broad export and download', function () {
    $f = m4Application();
    $owner = userWithRole(Role::Owner);
    $this->actingAs($owner);
    m4HttpException(fn () => app(ExportApplications::class)->create($owner, $f['job'], 'csv', 'Broad export'), 403);
    $this->withSession(['auth.password_confirmed_at' => now()->subMinutes(16)->timestamp]);
    m4HttpException(fn () => app(ExportApplications::class)->create($owner, $f['job'], 'csv', 'Expired confirmation'), 403);
    $this->withSession(['auth.password_confirmed_at' => now()->timestamp]);
    $artifact = app(ExportApplications::class)->create($owner, $f['job'], 'csv', 'Confirmed');
    $this->withSession(['auth.password_confirmed_at' => now()->subMinutes(16)->timestamp]);
    $this->get(route('review-exports.download', $artifact->id))->assertForbidden();
});

it('enforces exports permission plus scope and never includes another job', function () {
    $f = m4Application(null, ['name' => 'AuthorizedSyntheticName']);
    $other = m4Application(null, ['name' => 'OutOfScopeSyntheticName']);
    $manager = userWithRole(Role::HiringManager, [$f['job']]);
    $this->actingAs($manager);
    $artifact = app(ExportApplications::class)->create($manager, $f['job'], 'csv', 'Scoped');
    expect(app(ExportApplications::class)->download($manager, $artifact))->toContain('AuthorizedSyntheticName')->not->toContain('OutOfScopeSyntheticName');
    m4HttpException(fn () => app(ExportApplications::class)->create($manager, $other['job'], 'csv', 'Outside'), 403);
    foreach ([Role::Recruiter, Role::Coordinator, Role::Management, Role::Operator] as $role) {
        $user = userWithRole($role, [$f['job']]);
        $this->actingAs($user);
        m4HttpException(fn () => app(ExportApplications::class)->create($user, $f['job'], 'csv', 'No grant'), 403);
    }
});

it('rechecks creator scope and name blind at download, including direct URLs', function () {
    $f = m4Application();
    $manager = userWithRole(Role::HiringManager, [$f['job']]);
    $this->actingAs($manager);
    $artifact = app(ExportApplications::class)->create($manager, $f['job'], 'csv', 'Scoped');
    $this->actingAs(userWithRole(Role::Owner))->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->get(route('review-exports.download', $artifact->id))->assertForbidden();
    $this->actingAs($manager);
    $f['job']->update(['name_blind_review' => true]);
    $this->get(route('review-exports.download', $artifact->id))->assertForbidden();
    $f['job']->update(['name_blind_review' => false]);
    $manager->assignedJobs()->detach();
    $this->get(route('review-exports.download', $artifact->id))->assertForbidden();
});

it('expires exports at 24 hours and removes only their encrypted bytes', function () {
    $f = m4Application();
    $manager = userWithRole(Role::HiringManager, [$f['job']]);
    $this->actingAs($manager);
    $artifact = app(ExportApplications::class)->create($manager, $f['job'], 'csv', 'Temporary');
    Storage::disk('review_exports')->put('unrelated.bin', 'Do not remove');
    $this->travel(24)->hours();
    $this->get(route('review-exports.download', $artifact->id))->assertStatus(410);
    $this->artisan('recruit:purge-review-exports')->assertSuccessful();
    Storage::disk('review_exports')->assertMissing($artifact->path)->assertExists('unrelated.bin');
    expect($artifact->fresh()->purged_at)->not->toBeNull()->and(ReviewExport::query()->count())->toBe(1);
});

it('does not grant restricted export cells to recruiter roles', function () {
    $user = userWithRole(Role::Recruiter);
    expect($user->hasPermission(Permission::ExportsAll))->toBeFalse()->and($user->hasPermission(Permission::ExportsAssigned))->toBeFalse();
});

it('creates a broad export through the Filament password and purpose form', function () {
    $f = m4Application();
    $owner = userWithRole(Role::Owner);
    Livewire::actingAs($owner)->test(ListApplications::class)
        ->callAction('export', ['job' => $f['job']->id, 'format' => 'xlsx', 'purpose' => 'Weekly synthetic review', 'password' => 'password'])
        ->assertHasNoActionErrors()->assertRedirect(route('review-exports.download', ReviewExport::query()->sole()->id));
    expect(ReviewExport::query()->sole()->purpose_enc)->toBe('Weekly synthetic review');
});
