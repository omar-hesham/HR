<?php

/**
 * ARC-P0-02 / spec §9.3 + kickoff §5: every core table exists, channel-agnostic
 * source fields, raw-message fields, retention tracks and name-blind flags.
 */

use App\Domain\Privacy\NameBlindPolicy;
use App\Models\RecruitmentJob;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;

it('creates every core table of the data model', function (string $table) {
    expect(Schema::hasTable($table))->toBeTrue();
})->with([
    'users', 'roles', 'permissions', 'activity_log', 'sessions', 'jobs', 'failed_jobs',
    'mailboxes', 'intake_messages', 'document_sources', 'documents', 'extractions', 'candidates',
    'applications', 'recruitment_jobs', 'recruitment_job_user', 'criteria_versions', 'criteria',
    'scores', 'criterion_results', 'overrides', 'notes', 'status_events', 'ai_runs',
    'privacy_requests', 'deletion_ledger', 'settings', 'backup_runs', 'notice_outbox',
]);

it('records the channel and an external reference independent of email (kickoff §5.1)', function () {
    expect(Schema::hasColumns('applications', ['source', 'external_reference']))->toBeTrue()
        ->and(Schema::hasColumns('document_sources', ['source', 'external_reference', 'intake_message_id']))->toBeTrue()
        ->and(Schema::hasColumns('intake_messages', ['source']))->toBeTrue();
});

it('has raw-message-first fields on intake messages (kickoff §5.2)', function () {
    expect(Schema::hasColumns('intake_messages', ['raw_path', 'raw_sha256', 'raw_size', 'received_at', 'visible_at', 'outcome', 'reason']))->toBeTrue();
});

it('models the two retention tracks and the hard maximum (kickoff §5.5)', function () {
    expect(Schema::hasColumns('applications', ['retention_track', 'handed_over_at', 'retention_due_at', 'final_decision_at', 'last_activity_at']))->toBeTrue()
        ->and(Schema::hasColumns('candidates', ['retention_hard_due_at', 'retention_notice_at']))->toBeTrue();
});

it('stores name-blind preferences per user and per job (kickoff §5.6)', function () {
    expect(Schema::hasColumn('users', 'name_blind_review'))->toBeTrue()
        ->and(Schema::hasColumn('recruitment_jobs', 'name_blind_review'))->toBeTrue()
        ->and(NameBlindPolicy::applies(false, true))->toBeTrue()
        ->and(NameBlindPolicy::applies(true, null))->toBeTrue()
        ->and(NameBlindPolicy::applies(false, false))->toBeFalse();
});

it('keeps evaluation provenance on scores (ARC-P0-02)', function () {
    expect(Schema::hasColumns('scores', ['rules_version', 'schema_version', 'prompt_version', 'model_id', 'driver', 'criteria_version_id']))->toBeTrue();
});

it('has no age, gender, religion or location column on candidates', function () {
    $columns = Schema::getColumnListing('candidates');

    expect(array_intersect($columns, ['age', 'birth_date', 'gender', 'religion', 'marital_status', 'governorate', 'city', 'address', 'photo']))->toBe([]);
});

it('gives every migration a down() so reset and rollback work (found on MySQL 8.4)', function () {
    $missing = [];

    foreach (glob(database_path('migrations/*.php')) ?: [] as $file) {
        if (! str_contains((string) file_get_contents($file), 'function down(')) {
            $missing[] = basename($file);
        }
    }

    expect($missing)->toBe([]);
});

it('seeds roles, synthetic users and the illustrative demo job in testing', function () {
    $this->seed(DatabaseSeeder::class);

    expect(User::query()->where('email', 'like', '%@example.test')->count())->toBe(6)
        ->and(RecruitmentJob::query()->whereNotNull('current_criteria_version_id')->count())->toBe(1)
        ->and(Role::query()->count())->toBe(6);
});
