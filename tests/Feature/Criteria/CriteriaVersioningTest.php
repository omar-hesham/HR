<?php

/**
 * T-M4-01 / T-M4-02 / M4-P0-02: drafts, immutable approved versions, approval rights,
 * and rejection of invalid or protected criteria through the real actions.
 */

use App\Actions\Criteria\ApproveCriteriaVersion;
use App\Actions\Criteria\CriteriaValidationException;
use App\Actions\Criteria\SaveCriterion;
use App\Actions\Criteria\StartDraftVersion;
use App\Domain\Access\Permission;
use App\Domain\Access\Role;
use App\Domain\Criteria\CriteriaVersionStatus;
use App\Models\CriteriaVersion;
use App\Models\Criterion;
use App\Models\Exceptions\ImmutableRecordException;
use App\Models\RecruitmentJob;
use App\Models\Score;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Spatie\Activitylog\Models\Activity;

/**
 * @return array<string, mixed>
 */
function weightedSkill(int $weight = 40): array
{
    return [
        'type' => 'nice_to_have', 'field' => 'skills', 'operator' => 'contains_any',
        'target' => ['values' => ['PLC']], 'weight' => $weight,
        'label_ar' => 'خبرة في PLC', 'label_en' => 'PLC experience',
        'justification' => 'Lines are PLC-controlled.',
    ];
}

function approvedVersion(RecruitmentJob $job, User $owner): CriteriaVersion
{
    $draft = app(StartDraftVersion::class)->handle($job, $owner);
    app(SaveCriterion::class)->handle($draft, weightedSkill(), $owner);

    return app(ApproveCriteriaVersion::class)->handle($draft, $owner);
}

it('creates a new version on edit, keeps the old one read-only and old scores on their version (T-M4-01)', function () {
    $owner = userWithRole(Role::Owner);
    $job = RecruitmentJob::factory()->create();
    $v1 = approvedVersion($job, $owner);
    $oldScore = Score::factory()->create(['criteria_version_id' => $v1->id]);

    $v2 = app(StartDraftVersion::class)->handle($job, $owner);
    expect($v2->version)->toBe(2)
        ->and($v2->based_on_version_id)->toBe($v1->id)
        ->and($v2->criteria()->count())->toBe(1);

    app(SaveCriterion::class)->handle($v2, weightedSkill(60), $owner, $v2->criteria()->first());
    app(ApproveCriteriaVersion::class)->handle($v2, $owner);

    expect($v1->fresh()->status)->toBe(CriteriaVersionStatus::Superseded)
        ->and($v1->fresh()->criteria()->first()->weight)->toBe(40)
        ->and($v2->fresh()->status)->toBe(CriteriaVersionStatus::Approved)
        ->and($v2->fresh()->approved_by)->toBe($owner->id)
        ->and($v2->fresh()->approved_at)->not->toBeNull()
        ->and($job->fresh()->current_criteria_version_id)->toBe($v2->id)
        ->and($oldScore->fresh()->criteria_version_id)->toBe($v1->id);

    $approval = Activity::query()->where('event', 'criteria.approved')->latest('id')->first();
    expect($approval->properties['rescore_required'])->toBeTrue()
        ->and($approval->causer_id)->toBe($owner->id);
});

it('refuses to change an approved version or its criteria', function () {
    $owner = userWithRole(Role::Owner);
    $version = approvedVersion(RecruitmentJob::factory()->create(), $owner);
    $criterion = $version->criteria()->first();

    expect(fn () => $criterion->update(['weight' => 99]))->toThrow(ImmutableRecordException::class)
        ->and(fn () => $criterion->delete())->toThrow(ImmutableRecordException::class)
        ->and(fn () => $version->update(['change_note' => 'x']))->toThrow(ImmutableRecordException::class)
        ->and(fn () => Criterion::factory()->create(['criteria_version_id' => $version->id]))->toThrow(ImmutableRecordException::class)
        ->and(fn () => app(SaveCriterion::class)->handle($version, weightedSkill(), $owner))->toThrow(AuthorizationException::class);
});

it('keeps only one open draft per job', function () {
    $owner = userWithRole(Role::Owner);
    $job = RecruitmentJob::factory()->create();

    $first = app(StartDraftVersion::class)->handle($job, $owner);
    $second = app(StartDraftVersion::class)->handle($job, $owner);

    expect($second->id)->toBe($first->id);
});

it('lets a hiring manager draft but not approve without the explicit grant', function () {
    $job = RecruitmentJob::factory()->create();
    $manager = userWithRole(Role::HiringManager, [$job]);
    $draft = app(StartDraftVersion::class)->handle($job, $manager);
    app(SaveCriterion::class)->handle($draft, weightedSkill(), $manager);

    expect(fn () => app(ApproveCriteriaVersion::class)->handle($draft, $manager))->toThrow(AuthorizationException::class);

    $manager->givePermissionTo(Permission::CriteriaApprove->value);
    app(ApproveCriteriaVersion::class)->handle($draft, $manager->fresh());

    expect($draft->fresh()->status)->toBe(CriteriaVersionStatus::Approved);
});

it('blocks a hiring manager from drafting criteria for a job outside their scope', function () {
    $manager = userWithRole(Role::HiringManager, [RecruitmentJob::factory()->create()]);

    app(StartDraftVersion::class)->handle(RecruitmentJob::factory()->create(), $manager);
})->throws(AuthorizationException::class);

it('blocks recruiters, coordinators and management from editing criteria', function (Role $role) {
    $job = RecruitmentJob::factory()->create();

    app(StartDraftVersion::class)->handle($job, userWithRole($role, [$job]));
})->with([Role::Recruiter, Role::Coordinator, Role::Management, Role::Operator])->throws(AuthorizationException::class);

it('rejects protected and location criteria with a clear message (T-M4-02)', function (array $input, string $key) {
    $owner = userWithRole(Role::Owner);
    $draft = app(StartDraftVersion::class)->handle(RecruitmentJob::factory()->create(), $owner);

    try {
        app(SaveCriterion::class)->handle($draft, array_merge(weightedSkill(), $input), $owner);
        $this->fail('Expected a validation exception.');
    } catch (CriteriaValidationException $e) {
        expect($e->result->keys())->toContain($key)
            ->and(implode(' ', $e->messages()))->not->toBe('');
    }

    expect($draft->criteria()->count())->toBe(0);
})->with([
    'religion field' => [['field' => 'religion'], 'app.criteria.errors.field_protected'],
    'governorate field' => [['field' => 'governorate'], 'app.criteria.errors.field_protected'],
    'gender in label' => [['label_en' => 'Males only'], 'app.criteria.errors.protected_terms'],
    'invalid weight' => [['weight' => 150], 'app.criteria.errors.weight_range'],
]);

it('blocks approval of a version without a weighted criterion and shows the reason', function () {
    $owner = userWithRole(Role::Owner);
    $draft = app(StartDraftVersion::class)->handle(RecruitmentJob::factory()->create(), $owner);
    app(SaveCriterion::class)->handle($draft, [
        'type' => 'must_have', 'field' => 'shift_availability', 'operator' => 'is_explicitly_true', 'target' => [],
        'weight' => null, 'label_ar' => 'الاستعداد للعمل بالورديات', 'label_en' => 'Shift work', 'justification' => 'Rotating shifts.',
    ], $owner);

    try {
        app(ApproveCriteriaVersion::class)->handle($draft, $owner);
        $this->fail('Expected a validation exception.');
    } catch (CriteriaValidationException $e) {
        expect($e->result->keys())->toContain('app.criteria.errors.version_needs_weighted');
    }

    expect($draft->fresh()->status)->toBe(CriteriaVersionStatus::Draft);
});

it('shows the Arabic message for a blocked criterion in Arabic UI', function () {
    app()->setLocale('ar');
    $owner = userWithRole(Role::Owner);
    $draft = app(StartDraftVersion::class)->handle(RecruitmentJob::factory()->create(), $owner);

    try {
        app(SaveCriterion::class)->handle($draft, array_merge(weightedSkill(), ['field' => 'governorate']), $owner);
    } catch (CriteriaValidationException $e) {
        expect($e->messages()[0])->toContain('خاصية محمية');
    }
});

it('renders the criteria editor for the owner and read-only for management', function () {
    $owner = userWithRole(Role::Owner);
    $job = RecruitmentJob::factory()->create();
    $version = approvedVersion($job, $owner);

    $this->actingAs($owner)->get("/admin/criteria-versions/{$version->id}")->assertOk()->assertSee($job->title_ar);
    $this->actingAs(userWithRole(Role::Management, [$job]))->get("/admin/criteria-versions/{$version->id}")->assertOk();
    $this->actingAs(userWithRole(Role::Management))->get("/admin/criteria-versions/{$version->id}")->assertForbidden();
});
