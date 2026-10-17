<?php

use App\Actions\Review\RequestRescore;
use App\Domain\Access\Role;
use App\Domain\Applications\ApplicationStatus;
use App\Domain\Criteria\CriteriaVersionStatus;
use App\Filament\Pages\DuplicateQueue;
use App\Filament\Resources\Applications\ApplicationResource;
use App\Filament\Resources\Applications\Pages\CompareApplications as ComparePage;
use App\Filament\Resources\Applications\Pages\ListApplications;
use App\Filament\Resources\Applications\Pages\ViewApplication;
use App\Infrastructure\Storage\ContentEncrypter;
use App\Jobs\RescoreApplication;
use App\Models\AiRun;
use App\Models\CriteriaVersion;
use App\Models\Document;
use App\Models\Score;
use App\Services\Applications\ApplicationStatusService;
use App\Services\Review\CompareApplications;
use App\Services\Review\ReviewApplications;
use App\Services\Review\ReviewDashboard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Exceptions\MethodNotFoundException;
use Livewire\Livewire;

require_once __DIR__.'/../../Support/M4.php';

it('ranks deterministically by band score coverage receipt and id, grouping jobs', function () {
    $base = m4Application();
    $other = m4Application($base['job']);
    $third = m4Application($base['job']);
    $owner = userWithRole(Role::Owner);
    // Synthetic immutable score rows, constructed in their final form.
    foreach ([[$base, 90, 80, 'strong_match'], [$other, 90, 90, 'strong_match'], [$third, 99, 100, 'weak_match']] as [$item, $value, $coverage, $band]) {
        $score = Score::factory()->create(['application_id' => $item['application']->id, 'criteria_version_id' => $base['version']->id,
            'extraction_id' => $item['extraction']->id, 'score' => $value, 'coverage' => $coverage, 'band' => $band]);
        $item['application']->update(['current_score_id' => $score->id, 'received_at' => '2026-10-10 10:00:00']);
    }
    expect(app(ReviewApplications::class)->ranked($owner)->pluck('applications.id')->all())
        ->toBe([$other['application']->id, $base['application']->id, $third['application']->id]);
    // Exact equal inputs break ties on id.
    $equal = Score::factory()->create(['application_id' => $other['application']->id, 'criteria_version_id' => $base['version']->id,
        'extraction_id' => $other['extraction']->id, 'score' => 90, 'coverage' => 80, 'band' => 'strong_match']);
    $other['application']->update(['current_score_id' => $equal->id]);
    expect(app(ReviewApplications::class)->ranked($owner)->pluck('applications.id')->all())
        ->toBe([$base['application']->id, $other['application']->id, $third['application']->id]);
});

it('keeps older scores visible unranked and rescoring appends a current version', function () {
    $f = m4Application();
    $old = Score::factory()->create(['application_id' => $f['application']->id, 'criteria_version_id' => $f['version']->id,
        'extraction_id' => $f['extraction']->id, 'score' => 99, 'coverage' => 100, 'band' => 'strong_match']);
    $f['application']->update(['current_score_id' => $old->id]);
    $owner = userWithRole(Role::Owner);
    $version = CriteriaVersion::factory()->create(['recruitment_job_id' => $f['job']->id, 'version' => 2,
        'status' => CriteriaVersionStatus::Draft, 'thresholds' => m3Snapshot()]);
    foreach ($f['version']->criteria as $criterion) {
        $copy = $criterion->replicate();
        $copy->criteria_version_id = $version->id;
        $copy->save();
    }
    $version->forceFill(['status' => CriteriaVersionStatus::Approved, 'approved_at' => now()])->save();
    $f['job']->forceFill(['current_criteria_version_id' => $version->id])->save();
    expect(app(ReviewApplications::class)->ranked($owner)->count())->toBe(0)
        ->and(app(ReviewApplications::class)->stale($owner)->pluck('id')->all())->toBe([$f['application']->id]);
    Livewire::actingAs($owner)->test(ListApplications::class)->assertSee(__('review.needs_recalculation'))
        ->set('history', true)->assertSee(__('review.needs_recalculation'))->call('rescore', $f['application']->id)->assertSuccessful();
    Queue::assertPushed(RescoreApplication::class);
    app()->call([new RescoreApplication($f['application']->id), 'handle']);
    expect($f['application']->fresh()->currentScore->criteria_version_id)->toBe($version->id)
        ->and(Score::query()->findOrFail($old->id)->score)->toBe($old->score)
        ->and($f['application']->fresh()->currentScore->score)->toBe('65.00')
        ->and($f['application']->scores()->count())->toBe(3);
});

it('pins unscored applications through combinations of all ranking filters and history', function () {
    $f = m3Application(false);
    $owner = userWithRole(Role::Owner);
    $filters = ['job' => '999999', 'band' => 'strong_match', 'status' => 'hired', 'source' => 'email',
        'exception' => 'awaiting_budget', 'from' => '2030-01-01', 'until' => '2000-01-01'];
    $dashboard = app(ReviewDashboard::class);
    $active = [];
    foreach ($filters as $key => $value) {
        $active[$key] = $value;
        $data = $dashboard->data($owner, $active);
        expect($data['exceptions']->pluck('id')->all())->toContain($f['application']->id)
            ->and($data['unscored_count'])->toBe(1);
    }
    $component = Livewire::actingAs($owner)->test(ListApplications::class);
    foreach ($filters as $key => $value) {
        $component->set($key, $value)->assertSee($f['candidate']->name);
    }
    $component->set('history', true)->assertSee($f['candidate']->name);
});

it('treats a score on a replaced active CV as unranked', function () {
    $f = m4Application();
    $new = Document::factory()->create(['candidate_id' => $f['candidate']->id]);
    $f['candidate']->update(['active_document_id' => $new->id]);
    $owner = userWithRole(Role::Owner);
    expect(app(ReviewApplications::class)->ranked($owner)->count())->toBe(0)
        ->and(app(ReviewApplications::class)->stale($owner)->count())->toBe(1);
});

it('enforces list and direct detail scope for every role', function (Role $role, bool $list, bool $detail) {
    $f = m4Application();
    $user = userWithRole($role, [$f['job']]);
    $this->actingAs($user)->get(ApplicationResource::getUrl('index'))->assertStatus($list ? 200 : 403);
    $response = $this->actingAs($user)->get(ApplicationResource::getUrl('view', ['record' => $f['application']]));
    expect($response->status())->toBe($detail ? 200 : 404);
})->with([
    [Role::Owner, true, true], [Role::HiringManager, true, true], [Role::Recruiter, true, true],
    [Role::Management, true, false], [Role::Coordinator, false, false], [Role::Operator, false, false],
]);

it('refuses out of scope direct detail compare and rescore ids', function () {
    $f = m4Application();
    $other = m4Application($f['job']);
    $user = userWithRole(Role::Recruiter);
    $this->actingAs($user)->get(ApplicationResource::getUrl('view', ['record' => $f['application']]))->assertNotFound();
    $this->get(ApplicationResource::getUrl('compare', ['applications' => [$f['application']->id, $other['application']->id]]))->assertForbidden();
    m4HttpException(fn () => app(RequestRescore::class)->handle($f['application'], $user), 403);
});

it('renders no identity strings in blind rankings and comparison, restoring them when flags are off', function (string $flag) {
    $f = m4Application(null, ['name' => 'SyntheticPrivateName', 'email' => 'private@example.test', 'phone' => '+201001234567']);
    $second = m4Application($f['job'], ['name' => 'SecondPrivateName', 'email' => 'second@example.test', 'phone' => '+201002345678']);
    $owner = userWithRole(Role::Owner);
    ($flag === 'user' ? $owner : $f['job'])->forceFill(['name_blind_review' => true])->save();
    $list = Livewire::actingAs($owner)->test(ListApplications::class);
    $compare = Livewire::test(ComparePage::class, ['applications' => [$f['application']->id, $second['application']->id]]);
    foreach ([$list, $compare] as $component) {
        $component->assertDontSee('SyntheticPrivateName')->assertDontSee('private@example.test')->assertDontSee('+201001234567');
    }
    ($flag === 'user' ? $owner : $f['job'])->forceFill(['name_blind_review' => false])->save();
    Livewire::test(ListApplications::class)->assertSee('SyntheticPrivateName')->assertSee('private@example.test')->assertSee('+201001234567');
    Livewire::test(ComparePage::class, ['applications' => [$f['application']->id, $second['application']->id]])->assertSee('SyntheticPrivateName');
})->with(['user', 'job']);

it('redacts identity inside comparison evidence under name blind', function () {
    $f = m4Application(null, ['name' => 'SyntheticPrivateName', 'email' => 'private@example.test', 'phone' => '+201001234567']);
    $second = m4Application($f['job']);
    $owner = userWithRole(Role::Owner);
    $owner->update(['name_blind_review' => true]);
    $criterion = $f['version']->criteria->first();
    $f['score']->criterionResults()->create(['criterion_id' => $criterion->id, 'result' => 'met', 'confidence' => 1, 'source' => 'model',
        'evidence_enc' => json_encode([['quote' => 'SyntheticPrivateName private@example.test +201001234567']])]);
    Livewire::actingAs($owner)->test(ComparePage::class, ['applications' => [$f['application']->id, $second['application']->id]])
        ->assertDontSee('SyntheticPrivateName')->assertDontSee('private@example.test')->assertDontSee('+201001234567')->assertSee(__('review.redacted'));
});

it('compares two or three same job and version applications and refuses cross versions or jobs', function () {
    $f = m4Application();
    $second = m4Application($f['job']);
    $third = m4Application($f['job']);
    $different = m4Application();
    $owner = userWithRole(Role::Owner);
    $comparison = app(CompareApplications::class);
    expect($comparison->data($owner, [$f['application']->id, $second['application']->id, $third['application']->id]))->toHaveCount(3);
    Livewire::actingAs($owner)->test(ComparePage::class, ['applications' => [$f['application']->id, $different['application']->id]])->assertSee(__('review.compare_invalid'));
    $differentVersion = CriteriaVersion::factory()->create(['recruitment_job_id' => $f['job']->id, 'version' => 2]);
    $score = Score::factory()->create(['application_id' => $second['application']->id, 'criteria_version_id' => $differentVersion->id]);
    $second['application']->update(['current_score_id' => $score->id]);
    Livewire::test(ComparePage::class, ['applications' => [$f['application']->id, $second['application']->id]])->assertSee(__('review.compare_invalid'));
    expect($comparison->data($owner, [$f['application']->id, $second['application']->id], $f['version']->id))->toHaveCount(2);
});

it('shows not mentioned separately from not met with anchored evidence and encrypted history', function () {
    $f = m4Application();
    $owner = userWithRole(Role::Owner);
    Livewire::actingAs($owner)->test(ViewApplication::class, ['record' => $f['application']->id])
        ->assertSee(__('review.outcomes.not_mentioned'))->assertSee(__('review.outcomes.not_met'))
        ->assertSee('data-result="not_mentioned"', false)->assertSee('data-result="not_met"', false)
        ->assertSee('evidence-'.$f['document']->id.'-', false)->assertSee(__('review.sources.model'));
});

it('keeps coordinator and operator out of the duplicate decision surface', function (Role $role) {
    Livewire::actingAs(userWithRole($role))->test(DuplicateQueue::class)->assertForbidden();
})->with([Role::Coordinator, Role::Operator, Role::HiringManager, Role::Management]);

it('prevents calling the blind list query helper as a Livewire RPC', function () {
    $f = m4Application(null, ['name' => 'HiddenRpcCandidate']);
    $owner = userWithRole(Role::Owner);
    $owner->update(['name_blind_review' => true]);
    expect(fn () => Livewire::actingAs($owner)->test(ListApplications::class)->call('reviewData'))
        ->toThrow(MethodNotFoundException::class);
});

it('keeps full normalized CV text behind document permissions for management', function () {
    $f = m4Application();
    $f['extraction']->update(['normalized_text_enc' => $f['extraction']->normalizedText().' PRIVATE-CV-TEXT-SENTINEL']);
    app(ApplicationStatusService::class)->transition($f['application'], ApplicationStatus::Shortlisted, userWithRole(Role::Owner), 'Review');
    $management = userWithRole(Role::Management, [$f['job']]);
    Livewire::actingAs($management)->test(ViewApplication::class, ['record' => $f['application']->id])
        ->assertDontSee('PRIVATE-CV-TEXT-SENTINEL')->assertDontSee(__('review.normalized_preview'));
});

it('authorizes a historical normalized preview against its own document', function () {
    $f = m4Application();
    $other = m4Application();
    $other['extraction']->update(['normalized_text_enc' => 'RESTRICTED-HISTORICAL-CV-TEXT']);
    $historical = Score::factory()->create(['application_id' => $f['application']->id, 'criteria_version_id' => $f['version']->id,
        'extraction_id' => $other['extraction']->id, 'score' => 80, 'coverage' => 100, 'band' => 'strong_match']);
    $recruiter = userWithRole(Role::Recruiter, [$f['job']]);
    expect($recruiter->can('download', $f['document']))->toBeTrue()
        ->and($recruiter->can('download', $other['document']))->toBeFalse();
    Livewire::actingAs($recruiter)->test(ViewApplication::class, ['record' => $f['application']->id, 'scoreId' => $historical->id])
        ->assertDontSee('RESTRICTED-HISTORICAL-CV-TEXT');
});

it('renders validated M3 enrichment using its dedicated content encryption key', function () {
    $f = m4Application();
    $owner = userWithRole(Role::Owner);
    $run = AiRun::query()->create(['document_id' => $f['document']->id, 'purpose' => 'explain', 'driver' => 'fake',
        'model' => 'synthetic', 'prompt_version' => 'explain-v1', 'status' => 'succeeded', 'reserved_cost_egp' => 0, 'cost_egp' => 0]);
    DB::table('score_explanations')->insert(['score_id' => $f['score']->id,
        'body_enc' => app(ContentEncrypter::class)->encryptString('Validated synthetic enrichment'),
        'ai_run_id' => $run->id, 'created_at' => now(), 'updated_at' => now()]);
    Livewire::actingAs($owner)->test(ViewApplication::class, ['record' => $f['application']->id])->assertSee('Validated synthetic enrichment');
});
