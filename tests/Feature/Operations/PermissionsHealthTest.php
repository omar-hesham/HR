<?php

use App\Domain\Access\Role;
use App\Filament\Pages\SystemHealth;
use App\Jobs\ScoreApplication;
use App\Jobs\SendPrivacyNotice;
use App\Services\Operations\GoLiveCheck;
use App\Services\Operations\SystemHealthSnapshot;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

require_once __DIR__.'/../../Support/M4.php';

beforeEach(function () {
    Storage::fake('private');
    Storage::fake('review_exports');
});

it('enforces every new page for every role', function (string $url, array $statuses) {
    foreach ([Role::Owner, Role::HiringManager, Role::Recruiter, Role::Coordinator, Role::Management, Role::Operator] as $i => $role) {
        $this->actingAs(userWithRole($role))->get($url)->assertStatus($statuses[$i]);
    }
})->with([
    ['/admin/system-health', [200, 403, 403, 200, 403, 200]],
    ['/admin/retention-due', [200, 403, 403, 403, 403, 403]],
    ['/admin/privacy-requests', [200, 200, 200, 403, 403, 403]],
    ['/admin/backup-operations', [200, 403, 403, 403, 403, 200]],
    ['/admin/calibration', [200, 200, 403, 403, 403, 403]],
    ['/admin/privacy-operations-settings', [200, 403, 403, 403, 403, 403]],
]);

it('renders coordinator health without candidate pii secrets failed payloads or exceptions', function () {
    m4Application(null, ['name' => 'NeverShowInHealth', 'email' => 'health-private@example.test', 'phone' => '+201001231111']);
    DB::table('failed_jobs')->insert(['uuid' => 'synthetic-failed-ref', 'connection' => 'database', 'queue' => 'default', 'payload' => 'SECRET-FAILED-PAYLOAD', 'exception' => 'SECRET-EXCEPTION', 'failed_at' => now()]);
    $coordinator = userWithRole(Role::Coordinator);
    Livewire::actingAs($coordinator)->test(SystemHealth::class)->assertSee('synthetic-failed-ref')->assertDontSee('NeverShowInHealth')->assertDontSee('health-private@example.test')->assertDontSee('+201001231111')->assertDontSee('SECRET-FAILED-PAYLOAD')->assertDontSee('SECRET-EXCEPTION');
    m4HttpException(fn () => app(SystemHealthSnapshot::class)->retry($coordinator, 'synthetic-failed-ref'), 403);
});

it('grants job retry only to owner and operator by default', function () {
    foreach (Role::cases() as $role) {
        expect(userWithRole($role)->can('jobs.retry'))->toBe(in_array($role, [Role::Owner, Role::Operator], true));
    }
    $owner = userWithRole(Role::Owner);
    DB::table('failed_jobs')->insert(['uuid' => 'notice', 'connection' => 'database', 'queue' => 'default', 'payload' => json_encode(['displayName' => SendPrivacyNotice::class]), 'exception' => '', 'failed_at' => now()]);
    m4HttpException(fn () => app(SystemHealthSnapshot::class)->retry($owner, 'notice'), 422);
});

it('warns on defaults and unapproved launch inputs without contacting live services', function () {
    $checks = app(GoLiveCheck::class)->checks();
    expect($checks['criteria'])->toBe('missing')->and($checks['mailbox'])->toBe('missing')->and($checks['retention.applicant_days'])->toBe('warning')->and($checks['external_acceptance'])->toBe('external');
    $this->artisan('recruit:go-live-check', ['--json' => true])->assertExitCode(1);
});

it('keeps Arabic and English operations keys in parity', function () {
    $flatten = function (array $values, string $prefix = '') use (&$flatten): array {
        $keys = [];
        foreach ($values as $key => $value) {
            if (is_array($value)) {
                $keys = [...$keys, ...$flatten($value, $prefix.$key.'.')];
            } else {
                $keys[] = $prefix.$key;
            }
        }

        return $keys;
    };
    expect($flatten(require lang_path('ar/ops.php')))->toBe($flatten(require lang_path('en/ops.php')));
});

it('requeues an approved failed scoring job with an audited authorized retry', function () {
    config(['queue.default' => 'database']);
    $queue = app('queue')->connection('database');
    $queue->push(new ScoreApplication(987654), '', 'scoring');
    $payload = DB::table('jobs')->sole()->payload;
    DB::table('jobs')->delete();
    $parsed = json_decode($payload, true);
    DB::table('failed_jobs')->insert(['uuid' => $parsed['uuid'], 'connection' => 'database', 'queue' => 'scoring', 'payload' => $payload, 'exception' => 'synthetic', 'failed_at' => now()]);
    app(SystemHealthSnapshot::class)->retry(userWithRole(Role::Owner), $parsed['uuid']);
    expect(DB::table('failed_jobs')->count())->toBe(0)->and(DB::table('jobs')->where('queue', 'scoring')->count())->toBe(1)->and(DB::table('activity_log')->where('event', 'job.retried')->count())->toBe(1);
});
