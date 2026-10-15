<?php

/**
 * M10-P0-03 / T-M10-03 (view/download part): who viewed or downloaded a candidate
 * file is logged without CV text, and only permitted roles can read the log.
 */

use App\Actions\Documents\UploadDocuments;
use App\Domain\Access\Permission;
use App\Domain\Access\Role;
use App\Filament\Resources\ActivityLog\ActivityLogResource;
use App\Models\Document;
use App\Models\DocumentSource;
use App\Models\RecruitmentJob;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;

beforeEach(fn () => Storage::fake('private'));

function uploadedDocument(RecruitmentJob $job, string $file = '10_en_quality.docx'): Document
{
    $result = app(UploadDocuments::class)->handle([fixtureUpload($file)], userWithRole(Role::Owner), $job);

    return DocumentSource::query()->findOrFail($result->items[0]['source_id'])->document;
}

it('logs a CV download with actor, document and request id but no CV content', function () {
    $job = RecruitmentJob::factory()->create();
    $document = uploadedDocument($job);
    $manager = userWithRole(Role::HiringManager, [$job]);

    $response = $this->actingAs($manager)->get(route('documents.download', $document->uuid));

    $response->assertOk()
        ->assertHeader('Content-Disposition', 'attachment; filename="cv-'.substr($document->uuid, 0, 8).'.docx"')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
    expect($response->headers->get('Cache-Control'))->toContain('no-store');

    $log = Activity::query()->where('event', 'document.downloaded')->latest('id')->firstOrFail();

    expect($log->causer_id)->toBe($manager->id)
        ->and($log->subject_id)->toBe($document->id)
        ->and($log->properties['request_id'])->toBe($response->headers->get('X-Request-Id'))
        ->and(json_encode($log->properties))->not->toContain('Omneya')
        ->and(json_encode($log->properties))->not->toContain('example.test');
});

it('logs an inline view of a PDF as a view event', function () {
    requirePdftotext();
    $job = RecruitmentJob::factory()->create();
    $result = app(UploadDocuments::class)->handle([fixtureUpload('02_en_accountant.pdf')], userWithRole(Role::Owner), $job);
    $document = DocumentSource::query()->findOrFail($result->items[0]['source_id'])->document;

    $this->actingAs(userWithRole(Role::Owner))
        ->get(route('documents.download', ['document' => $document->uuid, 'inline' => 1]))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');

    expect(Activity::query()->where('event', 'document.viewed')->count())->toBe(1);
});

it('does not log or serve a download that was refused', function () {
    $document = uploadedDocument(RecruitmentJob::factory()->create());

    $this->actingAs(userWithRole(Role::Coordinator))->get(route('documents.download', $document->uuid))->assertForbidden();

    expect(Activity::query()->where('event', 'document.downloaded')->count())->toBe(0);
});

it('shows the access log to the owner only, unless explicitly granted for assigned jobs', function () {
    $job = RecruitmentJob::factory()->create();
    $other = RecruitmentJob::factory()->create();
    $mine = uploadedDocument($job);
    $theirs = uploadedDocument($other, '12_table_cv.docx');
    $owner = userWithRole(Role::Owner);
    $this->actingAs($owner)->get(route('documents.download', $mine->uuid));
    $this->actingAs($owner)->get(route('documents.download', $theirs->uuid));

    $this->actingAs($owner)->get('/admin/activity-log')->assertOk();

    $manager = userWithRole(Role::HiringManager, [$job]);
    $this->actingAs($manager)->get('/admin/activity-log')->assertForbidden();

    $manager->givePermissionTo(Permission::AccessLogViewAssigned->value);
    $manager = $manager->fresh();
    $this->actingAs($manager)->get('/admin/activity-log')->assertOk();

    $this->actingAs($manager);
    $visible = ActivityLogResource::getEloquentQuery()->pluck('subject_id')->all();
    expect($visible)->toContain($mine->id)->not->toContain($theirs->id);
});
