<?php

/**
 * T-M9-01: the §4 matrix, allowed AND denied, enforced on the server (pages and the
 * file download route), including direct access with an out-of-scope candidate.
 */

use App\Domain\Access\JobScope;
use App\Domain\Access\Permission;
use App\Domain\Access\Role;
use App\Domain\Applications\ApplicationStatus;
use App\Infrastructure\Storage\EncryptedDocumentStore;
use App\Models\Application;
use App\Models\Document;
use App\Models\DocumentSource;
use App\Models\RecruitmentJob;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

beforeEach(fn () => Storage::fake('private'));

/**
 * Expected HTTP status per role for each panel page.
 */
dataset('page_matrix', [
    // page                         owner hm   rec  coord mgmt oper
    'applications' => ['/admin/applications', [200, 200, 200, 403, 200, 403]],
    'needs review' => ['/admin/needs-review', [200, 200, 200, 403, 403, 403]],
    'upload' => ['/admin/upload', [200, 200, 200, 403, 403, 403]],
    'jobs' => ['/admin/recruitment-jobs', [200, 200, 200, 403, 200, 403]],
    'create job' => ['/admin/recruitment-jobs/create', [200, 403, 403, 403, 403, 403]],
    'users' => ['/admin/users', [200, 403, 403, 200, 403, 403]],
    'access log' => ['/admin/activity-log', [200, 403, 403, 403, 403, 403]],
    'settings' => ['/admin/settings', [200, 403, 403, 403, 403, 403]],
]);

it('enforces the page matrix for every role', function (string $url, array $expected) {
    $roles = [Role::Owner, Role::HiringManager, Role::Recruiter, Role::Coordinator, Role::Management, Role::Operator];

    foreach ($roles as $i => $role) {
        $this->actingAs(userWithRole($role))
            ->get($url)
            ->assertStatus($expected[$i]);
    }
})->with('page_matrix');

/**
 * A stored synthetic CV linked to an application for $job.
 */
function storedCv(?RecruitmentJob $job, ApplicationStatus $status = ApplicationStatus::Received): Document
{
    $store = app(EncryptedDocumentStore::class);

    $document = Document::factory()->create();
    $document->forceFill(['path' => $store->put($document->candidate->uuid, $document->uuid, '%PDF-1.4 synthetic')])->save();

    $application = Application::factory()->create([
        'candidate_id' => $document->candidate_id,
        'recruitment_job_id' => $job?->id,
        'status' => $status,
    ]);

    DocumentSource::factory()->create(['document_id' => $document->id, 'application_id' => $application->id]);

    return $document;
}

it('enforces CV download per role and job scope', function () {
    $assigned = RecruitmentJob::factory()->create();
    $other = RecruitmentJob::factory()->create();
    $inScope = storedCv($assigned);
    $outOfScope = storedCv($other);

    $cases = [
        // role, can download in-scope, can download out-of-scope
        [Role::Owner, 200, 200],
        [Role::HiringManager, 200, 403],
        [Role::Recruiter, 200, 403],
        [Role::Coordinator, 403, 403],
        [Role::Management, 403, 403],
        [Role::Operator, 403, 403],
    ];

    foreach ($cases as [$role, $in, $out]) {
        $user = userWithRole($role, [$assigned]);

        $this->actingAs($user)->get(route('documents.download', $inScope->uuid))->assertStatus($in);
        $this->actingAs($user)->get(route('documents.download', $outOfScope->uuid))->assertStatus($out);
    }
});

it('gives read-only management CV access only with an explicit grant and only for the shortlist', function () {
    $job = RecruitmentJob::factory()->create();
    $shortlisted = storedCv($job, ApplicationStatus::Shortlisted);
    $notShortlisted = storedCv($job, ApplicationStatus::Scored);
    $management = userWithRole(Role::Management, [$job]);

    $this->actingAs($management)->get(route('documents.download', $shortlisted->uuid))->assertForbidden();

    $management->givePermissionTo(Permission::DocumentsDownloadAssigned->value);
    $management = $management->fresh();

    $this->actingAs($management)->get(route('documents.download', $shortlisted->uuid))->assertOk();
    $this->actingAs($management)->get(route('documents.download', $notShortlisted->uuid))->assertForbidden();
});

it('lets the recruiter, but not the hiring manager, see unassigned applications', function () {
    $unassigned = storedCv(null);

    $this->actingAs(userWithRole(Role::Recruiter))->get(route('documents.download', $unassigned->uuid))->assertOk();
    $this->actingAs(userWithRole(Role::HiringManager))->get(route('documents.download', $unassigned->uuid))->assertForbidden();
});

it('scopes the applications list to the user\'s jobs', function () {
    $mine = RecruitmentJob::factory()->create(['title_en' => 'Mine job']);
    $theirs = RecruitmentJob::factory()->create(['title_en' => 'Theirs job']);
    storedCv($mine);
    storedCv($theirs);
    $manager = userWithRole(Role::HiringManager, [$mine]);

    $visible = JobScope::applyToApplications(Application::query(), $manager)->pluck('recruitment_job_id')->all();

    expect($visible)->toBe([$mine->id]);
});

it('does not let the coordinator read CVs or assign roles even with user management', function () {
    $coordinator = userWithRole(Role::Coordinator);
    $target = userWithRole(Role::Recruiter);
    $cv = storedCv(RecruitmentJob::factory()->create());

    expect($coordinator->can('create', User::class))->toBeTrue()
        ->and($coordinator->can('update', $target))->toBeTrue()
        ->and($coordinator->can('assignRoles', $target))->toBeFalse()
        ->and($coordinator->can('download', $cv))->toBeFalse()
        ->and($coordinator->can('update', userWithRole(Role::Owner)))->toBeFalse();

    $this->actingAs($coordinator)->get(route('documents.download', $cv->uuid))->assertForbidden();
});

it('never lets a user change their own roles', function () {
    $owner = userWithRole(Role::Owner);

    expect($owner->can('assignRoles', $owner))->toBeFalse()
        ->and($owner->can('assignRoles', userWithRole(Role::Recruiter)))->toBeTrue();
});

it('returns 404, not the file, for an unknown document id', function () {
    $this->actingAs(userWithRole(Role::Owner))
        ->get('/documents/00000000-0000-0000-0000-000000000000/download')
        ->assertNotFound();
});

it('redirects guests to the login page instead of serving files', function () {
    $cv = storedCv(RecruitmentJob::factory()->create());

    $this->get(route('documents.download', $cv->uuid))->assertRedirect(route('filament.admin.auth.login'));
});
