<?php

use App\Actions\Criteria\ApproveCriteriaVersion;
use App\Actions\Criteria\StartDraftVersion;
use App\Actions\Scoring\CorrectField;
use App\Actions\Scoring\EvaluateApplication;
use App\Actions\Scoring\OverrideScore;
use App\Domain\Access\Role;
use App\Domain\Criteria\CriteriaVersionStatus;
use App\Jobs\RescoreApplication;
use App\Models\Application;
use App\Models\CriteriaVersion;
use App\Models\Criterion;
use App\Models\Extraction;
use App\Models\RecruitmentJob;
use App\Models\Score;
use App\Services\Ai\AiWorkCoordinator;
use App\Services\Ai\RankedApplications;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\HttpKernel\Exception\HttpException;

it('preserves S65 while a reviewer correction creates S85 with old and new evidence and actor', function () {
    $d = m3Application();
    $owner = userWithRole(Role::Owner);
    $evaluator = app(EvaluateApplication::class);
    $old = $evaluator->handle($d['application']->id);
    $original = $d['extraction']->fresh()->fields_json_enc;
    $correction = app(CorrectField::class)->handle($d['application'], $d['extraction'], 'certifications.0', ['value' => 'safety_training', 'confidence' => 1, 'evidence' => [['quote' => 'Electrical safety training']]], $owner, 'Synthetic reviewer confirmed training');
    $new = $evaluator->handle($d['application']->id);
    expect($old->fresh()->score)->toBe('65.00')->and($new->score)->toBe('85.00')->and($new->band)->toBe('strong_match')->and($new->id)->not->toBe($old->id)->and(Score::query()->count())->toBe(2)->and($new->criterionResults()->orderBy('criterion_id')->get()[5]->source)->toBe('human_correction')->and($d['extraction']->fresh()->fields_json_enc)->toBe($original);
    $row = DB::table('field_corrections')->first();
    expect($row->user_id)->toBe($owner->id)->and($row->before_enc)->not->toBe('null')->and($row->after_enc)->not->toContain('Electrical safety training');
    expect($evaluator->handle($d['application']->id)->id)->toBe($new->id)->and(Score::query()->count())->toBe(2);
});
it('consumes criteria approval activity and reuses extraction for a new historical score', function () {
    $d = m3Application();
    $owner = userWithRole(Role::Owner);
    $old = app(EvaluateApplication::class)->handle($d['application']->id);
    $before = Extraction::query()->count();
    $draft = app(StartDraftVersion::class)->handle($d['job']->fresh(), $owner);
    $version = app(ApproveCriteriaVersion::class)->handle($draft, $owner);
    Queue::assertPushed(RescoreApplication::class, fn ($job) => $job->applicationId === $d['application']->id && $job->versionId === $version->id);
    $new = app(EvaluateApplication::class)->handle($d['application']->id, $version->id);
    expect($new->id)->not->toBe($old->id)->and($new->extraction_id)->toBe($old->extraction_id)->and($old->fresh()->criteria_version_id)->toBe($d['version']->id)->and(Extraction::query()->count())->toBe($before)->and(Score::query()->count())->toBe(2);
});
it('reuses a valid extraction for a different job without borrowing relevant years or double scoring', function () {
    $d = m3Application();
    $first = app(EvaluateApplication::class)->handle($d['application']->id);
    $job = RecruitmentJob::factory()->create();
    $version = CriteriaVersion::factory()->create(['recruitment_job_id' => $job->id, 'status' => CriteriaVersionStatus::Draft]);
    foreach ($d['version']->criteria as $criterion) {
        Criterion::query()->create([...$criterion->only(['type', 'field', 'operator', 'target_json', 'weight', 'label_ar', 'label_en', 'justification', 'legal_basis_note', 'sort']), 'criteria_version_id' => $version->id]);
    }
    $version->forceFill(['status' => CriteriaVersionStatus::Approved, 'approved_at' => now()])->save();
    $job->forceFill(['current_criteria_version_id' => $version->id])->save();
    $second = Application::factory()->create(['candidate_id' => $d['candidate']->id, 'recruitment_job_id' => $job->id]);
    $score = app(EvaluateApplication::class)->handle($second->id);
    expect($score->extraction_id)->toBe($first->extraction_id)->and($score->criterionResults()->orderBy('criterion_id')->get()[1]->result)->toBe('not_mentioned')->and($score->band)->toBe('needs_review')->and(Extraction::query()->count())->toBe(1);
    app(AiWorkCoordinator::class)->forApplication($d['application']->id);
    expect(app(EvaluateApplication::class)->handle($d['application']->id)->id)->toBe($first->id);
});
it('requires authorized scoped corrections and mandatory override reasons without mutating results', function () {
    $d = m3Application();
    $score = app(EvaluateApplication::class)->handle($d['application']->id);
    $owner = userWithRole(Role::Owner);
    $action = app(OverrideScore::class);
    expect(fn () => $action->handle($score, '90', $owner, ''))->toThrow(HttpException::class);
    $override = $action->handle($score, '90', $owner, 'Synthetic review reason');
    expect($override->old_value)->toBe('65.00')->and($score->fresh()->score)->toBe('65.00')->and($score->criterionResults()->count())->toBe(8);
    expect(fn () => $action->handle($score, '90', userWithRole(Role::Coordinator), 'Denied'))->toThrow(HttpException::class);
    expect(fn () => app(CorrectField::class)->handle($d['application'], $d['extraction'], 'city', ['value' => 'Cairo', 'confidence' => 1, 'evidence' => []], $owner, 'Denied'))->toThrow(HttpException::class);
});
it('orders within a band by S then C then earliest receipt and keeps unscored items visible', function () {
    $d = m3Application();
    $a = $d['application'];
    $score = app(EvaluateApplication::class)->handle($a->id);
    $b = Application::factory()->create(['candidate_id' => $d['candidate']->id, 'recruitment_job_id' => $d['job']->id, 'received_at' => now()->subHour()]);
    $sb = app(EvaluateApplication::class)->handle($b->id);
    $unscored = Application::factory()->create(['recruitment_job_id' => $d['job']->id]);
    $ids = app(RankedApplications::class)->query($d['job']->id)->pluck('applications.id')->all();
    expect($ids)->toBe([$b->id, $a->id, $unscored->id]);
    // Independent historical row with equal S and higher C demonstrates the query tie-break.
    $higher = Score::factory()->create(['application_id' => $a->id, 'criteria_version_id' => $d['version']->id, 'score' => 65, 'coverage' => 90, 'band' => 'needs_review']);
    $a->forceFill(['current_score_id' => $higher->id])->save();
    expect(app(RankedApplications::class)->query($d['job']->id)->pluck('applications.id')->all())->toBe([$a->id, $b->id, $unscored->id]);
});
