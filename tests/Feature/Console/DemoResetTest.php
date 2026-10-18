<?php

/**
 * recruit:demo-reset (demo-polish ideas 6+10): rehearsal-safe reseeding that
 * removes every synthetic review datum and its dependents, keeps users —
 * including their MFA enrolment — roles, permissions and settings untouched,
 * and can optionally poll the synthetic fixture mailbox once.
 */

use App\Actions\Criteria\StartDraftVersion;
use App\Actions\Review\AddNote;
use App\Actions\Scoring\CorrectField;
use App\Actions\Scoring\OverrideScore;
use App\Domain\Documents\ReviewReason;
use App\Models\Application;
use App\Models\Candidate;
use App\Models\CriteriaVersion;
use App\Models\Document;
use App\Models\DuplicateResolution;
use App\Models\Extraction;
use App\Models\FieldCorrection;
use App\Models\IntakeMessage;
use App\Models\Note;
use App\Models\Override;
use App\Models\RecruitmentJob;
use App\Models\ReviewExport;
use App\Models\Score;
use App\Models\User;
use App\Services\Review\ExportApplications;
use App\Services\Review\WeeklyReport;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

it('refuses to run outside the local environment and changes nothing', function () {
    Storage::fake('private');
    $this->seed(DatabaseSeeder::class);
    expect(app()->environment())->toBe('testing');

    $this->artisan('recruit:demo-reset')->assertFailed();

    expect(Application::query()->count())->toBe(12);
});

it('re-seeds demo review data after rehearsal mutations while keeping users, MFA, roles, permissions and settings', function () {
    Storage::fake('private');
    Storage::fake('review_exports');
    $this->seed(DatabaseSeeder::class);
    $owner = User::query()->where('email', 'owner@example.test')->firstOrFail();
    $owner->forceFill(['app_authentication_secret' => 'JBSWY3DPEHPK3PXPSYNTHETIC'])->save();
    $secret = DB::table('users')->where('id', $owner->id)->value('app_authentication_secret');
    $job = RecruitmentJob::query()->where('title_en', 'DEMO - Electrical maintenance technician')->firstOrFail();
    app(StartDraftVersion::class)->handle($job, $owner); // Live rehearsal draft (version 3).

    $application = Application::query()->where('external_reference', 'm4a-demo-0')->firstOrFail();
    app(AddNote::class)->handle($application, $owner, 'Rehearsal note');
    $score = Score::query()->where('application_id', $application->id)->firstOrFail();
    app(OverrideScore::class)->handle($score, '90', $owner, 'Rehearsal override');
    $extraction = Extraction::query()->where('document_id', $application->candidate->active_document_id)->firstOrFail();
    app(CorrectField::class)->handle($application, $extraction, 'certifications.0',
        ['value' => 'electrical safety', 'confidence' => 1, 'evidence' => [['quote' => 'Electrical safety training']]],
        $owner, 'Rehearsal correction');
    DB::table('document_sources')->where('application_id', $application->id)
        ->update(['review_resolved_at' => now(), 'review_resolved_by' => $owner->id, 'review_note_enc' => 'Rehearsal resolve']);
    IntakeMessage::factory()->create();
    DB::table('ai_runs')->insert(['document_id' => $extraction->document_id, 'purpose' => 'extract', 'driver' => 'fake',
        'model' => 'synthetic', 'prompt_version' => 'extract-v1', 'status' => 'done', 'created_at' => now(), 'updated_at' => now()]);
    DuplicateResolution::query()->create(['existing_candidate_id' => $application->candidate_id, 'incoming_candidate_id' => Candidate::query()->max('id'),
        'fingerprint' => sha1('rehearsal-resolution'), 'action' => 'keep_two', 'user_id' => $owner->id,
        'reason_enc' => 'Rehearsal resolution', 'links_enc' => '[]', 'created_at' => now()]);
    session()->put('auth.password_confirmed_at', now()->timestamp);
    app(ExportApplications::class)->create($owner, $job, 'csv', 'Rehearsal export');

    $userRows = DB::table('users')->get();
    $roleRows = DB::table('roles')->get();
    $permissionRows = DB::table('permissions')->get();
    $settingRows = DB::table('settings')->get();

    app()['env'] = 'local';
    $this->artisan('recruit:demo-reset')->assertSuccessful();

    expect(Application::query()->count())->toBe(12)
        ->and(Candidate::query()->count())->toBe(12)
        ->and(Document::query()->count())->toBe(12)
        ->and(Note::query()->count())->toBe(0)
        ->and(FieldCorrection::query()->count())->toBe(0)
        ->and(Override::query()->count())->toBe(0)
        ->and(ReviewExport::query()->count())->toBe(0)
        ->and(IntakeMessage::query()->count())->toBe(0)
        ->and(DB::table('ai_runs')->count())->toBe(0)
        ->and(DB::table('duplicate_resolutions')->count())->toBe(0)
        ->and(DB::table('document_sources')->where('review_reason', ReviewReason::LowQuality->value)->whereNull('review_resolved_at')->count())->toBe(1)
        ->and($job->fresh()->currentCriteriaVersion->version)->toBe(2)
        ->and(CriteriaVersion::query()->where('recruitment_job_id', $job->id)->orderBy('version')->pluck('version')->all())->toBe([1, 2])
        ->and(DB::table('users')->pluck('email')->sort()->values()->all())->toBe($userRows->pluck('email')->sort()->values()->all())
        ->and(DB::table('users')->where('id', $owner->id)->value('app_authentication_secret'))->toBe($secret)
        ->and(DB::table('roles')->pluck('name')->sort()->values()->all())->toBe($roleRows->pluck('name')->sort()->values()->all())
        ->and(DB::table('permissions')->pluck('name')->sort()->values()->all())->toBe($permissionRows->pluck('name')->sort()->values()->all())
        ->and(DB::table('settings')->pluck('key')->sort()->values()->all())->toBe($settingRows->pluck('key')->sort()->values()->all());
    foreach (Document::query()->pluck('path') as $path) {
        expect(Storage::disk('private')->exists($path))->toBeTrue();
    }

    $this->artisan('recruit:demo-reset')->assertSuccessful();
    expect(Application::query()->count())->toBe(12)
        ->and(CriteriaVersion::query()->where('recruitment_job_id', $job->id)->orderBy('version')->pluck('version')->all())->toBe([1, 2]);
});

it('optionally polls the synthetic fixture mailbox so intake counts are not zero', function () {
    Storage::fake('private');
    $this->seed(DatabaseSeeder::class);
    app()['env'] = 'local';

    $this->artisan('recruit:demo-reset', ['--with-mail' => true])->assertSuccessful();

    $owner = User::query()->where('email', 'owner@example.test')->firstOrFail();
    $report = app(WeeklyReport::class)->data($owner, '2026-09-28');
    expect(IntakeMessage::query()->count())->toBeGreaterThan(0)
        ->and($report['counts']['intake'])->toBeGreaterThan(0)
        ->and($report['visibility'])->not->toBeEmpty();
});
