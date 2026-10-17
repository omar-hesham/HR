<?php

use App\Domain\Access\Role;
use App\Domain\Applications\ApplicationStatus;
use App\Models\Application;
use App\Services\Privacy\PrivacyRequests;
use App\Services\Privacy\ProcessingRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/../../Support/M4.php';

beforeEach(function () {
    Storage::fake('private');
    Storage::fake('review_exports');
    Mail::fake();
});

it('executes a verified approved erasure immediately across every active status', function () {
    $f = m4Application();
    foreach ([ApplicationStatus::Processing, ApplicationStatus::NeedsReview, ApplicationStatus::Scored, ApplicationStatus::Shortlisted, ApplicationStatus::OnHold, ApplicationStatus::Interview] as $status) {
        Application::factory()->create(['candidate_id' => $f['candidate']->id, 'recruitment_job_id' => $f['job']->id, 'status' => $status]);
    }
    $owner = userWithRole(Role::Owner);
    $recruiter = userWithRole(Role::Recruiter, [$f['job']]);
    $service = app(PrivacyRequests::class);
    // Requester needs scope over every application, including unassigned applications.
    $request = $service->register($recruiter, $f['candidate'], 'erasure', 'Approved manual channel', 'Minimal synthetic request');
    m4HttpException(fn () => $service->decide($recruiter, $request, 'approved', 'Try to decide'), 403);
    m4HttpException(fn () => $service->decide($owner, $request, 'approved', 'Not verified'), 422);
    $service->verify($owner, $request, 'Existing contact challenge; no ID copy');
    $service->decide($owner, $request->fresh(), 'approved', 'Verified request');
    expect(Application::query()->where('status', '!=', 'deleted')->count())->toBe(0)->and($request->fresh()->status)->toBe('completed')->and($request->fresh()->candidate_id)->toBeNull();
    $service->notify($owner, $request->fresh(), 'Delivery confirmed');
    expect($request->fresh()->getAttribute('notified_at'))->not->toBeNull()->and($request->fresh()->getAttribute('mailbox_read_only'))->toBe(1)->and($request->fresh()->getAttribute('provider_deletion_factory_responsibility'))->toBe(1);
    Mail::assertNothingSent();
});

it('exports complete encrypted subject data with scoped audited download and 24h expiry', function () {
    $f = m4Application();
    $owner = userWithRole(Role::Owner);
    $service = app(PrivacyRequests::class);
    $request = $service->register($owner, $f['candidate'], 'access', 'Approved offline delivery', 'Access request');
    $service->verify($owner, $request, 'Contact verification');
    $service->decide($owner, $request->fresh(), 'approved', 'Approved');
    $artifact = DB::table('privacy_artifacts')->sole();
    $bytes = $service->download($owner, $artifact->id);
    $payload = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
    expect($payload['subjects'][0]['name'])->toBe('Samir Hassan')->and($payload['documents'][0]['original_base64'])->toBe(base64_encode('%PDF-synthetic'));
    expect(Storage::disk('private')->get($artifact->path))->not->toContain('Samir Hassan');
    $this->actingAs(userWithRole(Role::Coordinator))->get(route('privacy-artifacts.download', $artifact->id))->assertForbidden();
    $this->travel(24)->hours();
    m4HttpException(fn () => $service->download($owner, $artifact->id), 410);
    $service->purgeExpired();
    Storage::disk('private')->assertMissing($artifact->path);
    expect(DB::table('activity_log')->where('event', 'privacy.downloaded')->count())->toBe(1);
    Mail::assertNothingSent();
});

it('refuses subject requests across job scope and manager decisions', function () {
    $f = m4Application();
    $hm = userWithRole(Role::HiringManager);
    $service = app(PrivacyRequests::class);
    m4HttpException(fn () => $service->register($hm, $f['candidate'], 'access', 'Manual', 'Out of scope'), 403);
    $hm->assignedJobs()->attach($f['job']);
    $hm = $hm->fresh();
    $request = $service->register($hm, $f['candidate'], 'rectification', 'Manual', 'Correction');
    m4HttpException(fn () => $service->verify($hm, $request, 'Cannot verify'), 403);
});

it('exports the required processing record without a legal compliance claim', function () {
    m3Settings(['processing.purpose' => 'Assisted recruitment', 'processing.categories' => 'CV and contacts', 'processing.recipients' => 'Authorized factory staff', 'processing.storage_location' => 'Approved Egypt storage']);
    $record = app(ProcessingRecord::class)->export(userWithRole(Role::Owner));
    foreach (['purpose', 'categories', 'recipients', 'storage_location', 'criteria_versions', 'retention', 'disclaimer'] as $key) {
        expect($record)->toHaveKey($key);
    }
    expect($record['disclaimer'])->toBe(__('ops.not_certificate'));
    m4HttpException(fn () => app(ProcessingRecord::class)->export(userWithRole(Role::Coordinator)), 403);
});

it('executes rectification through the existing allowlist action and preserves prior scores', function () {
    $f = m4Application();
    $owner = userWithRole(Role::Owner);
    $service = app(PrivacyRequests::class);
    $request = $service->register($owner, $f['candidate'], 'rectification', 'Manual approved channel', 'Correct skill');
    $service->verify($owner, $request, 'Verified existing contact');
    $service->decide($owner, $request->fresh(), 'approved', 'Correction approved');
    $service->rectify($owner, $request->fresh(), $f['application'], ['path' => 'skills.0', 'value' => 'PLC programming', 'evidence' => 'Diagnosing PLC faults', 'reason' => 'Verified against original synthetic evidence']);
    expect($request->fresh()->status)->toBe('completed')->and(DB::table('field_corrections')->count())->toBe(1)->and($f['score']->fresh())->not->toBeNull();
    Mail::assertNothingSent();
});
