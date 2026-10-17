<?php

use App\Actions\Privacy\DeleteCandidateData;
use App\Domain\Access\Role;
use App\Domain\Privacy\DeletionReason;
use App\Infrastructure\Storage\ContentEncrypter;
use App\Jobs\NightlyBackup;
use App\Jobs\RetentionSweep;
use App\Models\BackupRun;
use App\Services\Operations\Backups;
use App\Services\Operations\DatabaseSnapshot;
use App\Services\Operations\RestoreVerification;
use App\Services\Operations\SystemHealthSnapshot;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Support/M4.php';

beforeEach(function () {
    Storage::fake('private');
    Storage::fake('review_exports');
    config(['operations.backup_target' => storage_path('app/private/ops/test-backups-'.Str::uuid()), 'operations.backup_key' => ContentEncrypter::generateKey(), 'operations.ledger_target' => '']);
});

it('backs up and restores database files and audit before replaying the current ledger', function () {
    $f = m4Application();
    activity('review')->performedOn($f['application'])->event('synthetic.view')->log('synthetic.view');
    Storage::disk('review_exports')->put('synthetic.bin', 'encrypted-synthetic-file');
    $run = app(Backups::class)->create();
    $archive = File::get(config('operations.backup_target').'/'.$run->getAttribute('location_ref'));
    expect($archive)->not->toContain('Samir Hassan')->not->toContain(config('app.key'))->not->toStartWith('PK');
    app(DeleteCandidateData::class)->candidate($f['candidate']->id, DeletionReason::ErasureRequest);
    $sourceAdapter = Storage::disk('private');
    $root = app(RestoreVerification::class)->verify($run);
    expect(Storage::disk('private'))->toBe($sourceAdapter);
    expect(is_file($root.'/OPEN'))->toBeTrue()->and($run->fresh()->getAttribute('restore_test_at'))->not->toBeNull();
    $report = json_decode(File::get($root.'/verification.json'), true);
    expect($report['database_match'])->toBeTrue()->and($report['audit_match'])->toBeTrue()->and($report['ledger_replayed'])->toBeGreaterThan(0);
    expect(is_file($root.'/private/'.$f['document']->path))->toBeFalse()->and(File::get($root.'/review_exports/synthetic.bin'))->toBe('encrypted-synthetic-file');
    $pdo = new PDO('sqlite:'.$root.'/database.sqlite');
    expect($pdo->query('select name from candidates where id='.$f['candidate']->id)->fetchColumn())->toBeNull();
    expect((int) $pdo->query('select count(*) from extractions')->fetchColumn())->toBe(0);
    expect(DB::getDefaultConnection())->toBe('sqlite')->and($f['application']->fresh()->status->value)->toBe('deleted');
});

it('fails closed for tampered archives without recording a drill', function () {
    m4Application();
    $run = app(Backups::class)->create();
    File::put(config('operations.backup_target').'/'.$run->getAttribute('location_ref'), 'tampered');
    expect(fn () => app(RestoreVerification::class)->verify($run))->toThrow(DecryptException::class);
    expect($run->fresh()->getAttribute('restore_test_at'))->toBeNull();
});

it('requires separate backup key and records safe failed status', function () {
    config(['operations.backup_key' => '']);
    expect(fn () => app(Backups::class)->create())->toThrow(RuntimeException::class, 'backup_failed');
    expect(BackupRun::query()->sole()->status)->toBe('failed');
});

it('reports stale backups and has the required retry contracts', function () {
    expect(app(Backups::class)->health()['fresh'])->toBeFalse()->and((new NightlyBackup)->tries)->toBe(2)->and((new RetentionSweep)->tries)->toBe(3);
    $run = app(Backups::class)->create();
    expect(app(Backups::class)->health()['fresh'])->toBeTrue();
    $this->travel(25)->hours();
    expect(app(Backups::class)->health()['fresh'])->toBeFalse();
    app(SystemHealthSnapshot::class)->monitor();
    app(SystemHealthSnapshot::class)->monitor();
    expect(DB::table('activity_log')->where('event', 'backup.stale')->count())->toBe(1);
});

it('replays the separately published latest ledger when live ledger rows are unavailable', function () {
    $f = m4Application();
    config(['operations.ledger_target' => storage_path('app/private/ops/current-ledger-'.Str::uuid().'.bin')]);
    $run = app(Backups::class)->create();
    app(DeleteCandidateData::class)->candidate($f['candidate']->id, DeletionReason::ErasureRequest);
    DB::table('deletion_ledger')->delete();
    $root = app(RestoreVerification::class)->verify($run);
    expect(is_file($root.'/OPEN'))->toBeTrue()->and(is_file($root.'/private/'.$f['document']->path))->toBeFalse();
    $pdo = new PDO('sqlite:'.$root.'/database.sqlite');
    expect((int) $pdo->query('select count(*) from extractions')->fetchColumn())->toBe(0)->and((int) $pdo->query('select count(*) from deletion_ledger')->fetchColumn())->toBeGreaterThan(0);
});

it('rejects schema-qualified and unexpected restore tables before changing any data', function () {
    $owner = userWithRole(Role::Owner);
    DB::statement("ATTACH DATABASE ':memory:' AS external_schema");
    DB::statement('CREATE TABLE external_schema.unrelated_records (id INTEGER PRIMARY KEY)');
    $dump = app(DatabaseSnapshot::class)->capture();
    expect(array_keys($dump))->not->toContain('unrelated_records');
    expect(array_keys($dump))->toContain('users');
    $foreign = $dump;
    $foreign['live_database.users'] = $foreign['users'];
    unset($foreign['users']);
    expect(fn () => app(DatabaseSnapshot::class)->load($foreign))->toThrow(RuntimeException::class, 'restore_table_set_mismatch');
    expect($owner->fresh())->not->toBeNull()->and(DB::table('users')->count())->toBe(1);
    $unexpected = $dump;
    $unexpected['unexpected_table'] = [];
    expect(fn () => app(DatabaseSnapshot::class)->load($unexpected))->toThrow(RuntimeException::class, 'restore_table_set_mismatch');
    expect($owner->fresh())->not->toBeNull();
});
