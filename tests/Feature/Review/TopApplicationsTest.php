<?php

use App\Domain\Access\Role;
use App\Domain\Applications\ApplicationStatus;
use App\Filament\Resources\Applications\Pages\ListApplications;
use App\Models\Application;
use App\Models\CriterionResult;
use App\Models\Document;
use App\Models\Score;
use App\Services\Review\ReviewApplications;
use App\Services\Review\ReviewDashboard;
use Livewire\Livewire;

require_once __DIR__.'/../../Support/M4.php';

it('shows exactly the first three current ranked applications for each visible job without changing status', function () {
    $first = m4Application();
    for ($index = 0; $index < 4; $index++) {
        m4Application($first['job']);
    }
    $other = m4Application();
    $owner = userWithRole(Role::Owner);
    $before = Application::query()->pluck('status', 'id')->all();
    $cards = app(ReviewDashboard::class)->data($owner, [])['top'];
    expect($cards)->toHaveCount(2)
        ->and(array_column($cards[0]['entries'], 'id'))->toBe(app(ReviewApplications::class)->ranked($owner, ['job' => $first['job']->id])->limit(3)->pluck('applications.id')->all())
        ->and($cards[1]['entries'][0]['id'])->toBe($other['application']->id);
    Livewire::actingAs($owner)->test(ListApplications::class)->assertSee(__('brain.suggestion'))->assertSee('data-tour="top-three"', false);
    expect(Application::query()->pluck('status', 'id')->all())->toBe($before);
    $first['candidate']->update(['active_document_id' => Document::factory()->create(['candidate_id' => $first['candidate']->id])->id]);
    $topIds = array_column(app(ReviewDashboard::class)->data($owner, [])['top'][0]['entries'], 'id');
    expect($topIds)->not->toContain($first['application']->id);
    $first['job']->forceFill(['current_criteria_version_id' => null])->save();
    expect(app(ReviewDashboard::class)->data($owner, [])['top'][0]['entries'])->toBe([]);
});

it('scopes top-three suggestions to assigned jobs and approved shortlist permissions', function () {
    $assigned = m4Application();
    $hidden = m4Application();
    $recruiter = userWithRole(Role::Recruiter, [$assigned['job']]);
    $cards = app(ReviewDashboard::class)->data($recruiter, ['job' => $hidden['job']->id])['top'];
    expect($cards)->toBe([]);
    $cards = app(ReviewDashboard::class)->data($recruiter, [])['top'];
    expect(array_column($cards, 'job_id'))->toBe([$assigned['job']->id]);
    $management = userWithRole(Role::Management, [$assigned['job']]);
    expect(app(ReviewDashboard::class)->data($management, [])['top'])->toBe([]);
    $assigned['application']->update(['status' => ApplicationStatus::Shortlisted]);
    expect(app(ReviewDashboard::class)->data($management, [])['top'][0]['entries'][0]['id'])->toBe($assigned['application']->id);
});

it('redacts identities in suggestion quotes and shows missing must-haves and model source', function () {
    $fixture = m4Application(null, ['name' => 'SyntheticPrivate Person', 'email' => 'private@example.test']);
    $score = Score::factory()->create(['application_id' => $fixture['application']->id, 'criteria_version_id' => $fixture['version']->id,
        'extraction_id' => $fixture['extraction']->id, 'score' => 70, 'coverage' => 65, 'band' => 'needs_review']);
    $criteria = $fixture['version']->criteria;
    CriterionResult::query()->create(['score_id' => $score->id, 'criterion_id' => $criteria[0]->id, 'result' => 'not_mentioned', 'source' => 'model', 'confidence' => 0, 'evidence_enc' => '[]']);
    CriterionResult::query()->create(['score_id' => $score->id, 'criterion_id' => $criteria[3]->id, 'result' => 'met', 'source' => 'model', 'confidence' => .95,
        'evidence_enc' => json_encode([['quote' => '<script>SyntheticPrivate Person private@example.test PLC</script>']], JSON_THROW_ON_ERROR)]);
    $fixture['application']->update(['current_score_id' => $score->id]);
    $owner = userWithRole(Role::Owner);
    $owner->update(['name_blind_review' => true]);
    $entry = app(ReviewDashboard::class)->data($owner, [])['top'][0]['entries'][0];
    expect($entry['missing'][0]['outcome'])->toBe('not_mentioned')
        ->and($entry['quotes'][0]['quote'])->not->toContain('SyntheticPrivate')->not->toContain('private@example.test')
        ->and($entry['quotes'][0]['source'])->toBe('model');
    Livewire::actingAs($owner)->test(ListApplications::class)->assertSee(__('brain.missing'))->assertSee(__('review.sources.model'))
        ->assertDontSee('SyntheticPrivate')->assertDontSee('private@example.test')->assertDontSee('<script>', false);
});
