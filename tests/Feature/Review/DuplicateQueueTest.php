<?php

use App\Actions\Review\ResolveDuplicate;
use App\Domain\Access\Role;
use App\Filament\Pages\DuplicateQueue as QueuePage;
use App\Models\Application;
use App\Models\DuplicateResolution;
use App\Models\Exceptions\ImmutableRecordException;
use App\Models\RecruitmentJob;
use App\Models\Score;
use App\Services\Review\DuplicateQueue;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

require_once __DIR__.'/../../Support/M4.php';

function m4DuplicatePair(): array
{
    $first = m4Application(null, ['name' => 'Existing Synthetic Name', 'email' => 'shared@example.test', 'email_hmac' => str_repeat('a', 64)]);
    $second = m4Application($first['job'], ['name' => 'Conflicting Synthetic Name', 'email' => 'shared@example.test', 'email_hmac' => str_repeat('a', 64)]);

    return [$first, $second];
}

it('queues shared contact conflicts with highlighted differences and secure CVs', function () {
    [$a, $b] = m4DuplicatePair();
    $owner = userWithRole(Role::Owner);
    expect(app(DuplicateQueue::class)->pairs($owner))->toHaveCount(1);
    Livewire::actingAs($owner)->test(QueuePage::class)->assertSee('Existing Synthetic Name')->assertSee('Conflicting Synthetic Name')
        ->assertSee('review-diff', false)->assertSee(route('documents.download', $a['document']->uuid), false);
});

it('never queues or permits merging by name alone', function () {
    $a = m4Application(null, ['name' => 'Same Synthetic Name']);
    $b = m4Application($a['job'], ['name' => 'Same Synthetic Name']);
    $owner = userWithRole(Role::Owner);
    expect(app(DuplicateQueue::class)->pairs($owner))->toBe([]);
    m4HttpException(fn () => app(ResolveDuplicate::class)->handle($a['candidate']->id, $b['candidate']->id, 'merge', 'Name only', $owner), 403);
});

it('resolves each duplicate action with encrypted reason and immutable links, preserving scores', function (string $action) {
    [$a, $b] = m4DuplicatePair();
    $owner = userWithRole(Role::Owner);
    $beforeScores = Score::query()->count();
    $row = app(ResolveDuplicate::class)->handle($a['candidate']->id, $b['candidate']->id, $action, 'Synthetic duplicate decision', $owner, true);
    expect($row->reason_enc)->toBe('Synthetic duplicate decision')
        ->and(DB::table('duplicate_resolutions')->value('reason_enc'))->not->toContain('Synthetic duplicate decision')
        ->and(json_decode($row->links_enc, true))->toHaveKeys(['before', 'after'])
        ->and(Score::query()->count())->toBe($beforeScores);
    expect(app(DuplicateQueue::class)->pairs($owner))->toBe([]);
    expect(Activity::query()->where('event', 'duplicate.'.$action)->sole()->causer_id)->toBe($owner->id);
    if ($action === 'keep_two') {
        expect($b['application']->fresh()->candidate_id)->toBe($b['candidate']->id)
            ->and($a['candidate']->fresh()->active_document_id)->toBe($a['document']->id);
    } else {
        expect($b['application']->fresh()->candidate_id)->toBe($a['candidate']->id)
            ->and($a['candidate']->applications()->count())->toBe(2)
            ->and($b['document']->fresh()->candidate_id)->toBe($a['candidate']->id)
            ->and($b['candidate']->fresh()->merged_into_id)->toBe($a['candidate']->id);
        expect($a['candidate']->fresh()->active_document_id)->toBe($action === 'append_active' ? $b['document']->id : $a['document']->id);
    }
    expect(fn () => $row->forceFill(['action' => 'changed'])->save())->toThrow(ImmutableRecordException::class);
})->with(['merge', 'keep_two', 'append_active']);

it('requires a reason and explicit activation confirmation in the action and the UI', function () {
    [$a, $b] = m4DuplicatePair();
    $owner = userWithRole(Role::Owner);
    m4HttpException(fn () => app(ResolveDuplicate::class)->handle($a['candidate']->id, $b['candidate']->id, 'merge', ' ', $owner), 422);
    m4HttpException(fn () => app(ResolveDuplicate::class)->handle($a['candidate']->id, $b['candidate']->id, 'append_active', 'Activate', $owner), 422);
    Livewire::actingAs($owner)->test(QueuePage::class)
        ->callAction('resolveDuplicate', ['existing' => $a['candidate']->id, 'incoming' => $b['candidate']->id, 'resolution' => 'keep_two', 'reason' => ''])
        ->assertHasActionErrors(['reason' => 'required']);
    expect(DuplicateResolution::query()->count())->toBe(0);
});

it('executes resolution through the rendered Filament action', function () {
    [$a, $b] = m4DuplicatePair();
    $owner = userWithRole(Role::Owner);
    Livewire::actingAs($owner)->test(QueuePage::class)
        ->callAction('resolveDuplicate', ['existing' => $a['candidate']->id, 'incoming' => $b['candidate']->id, 'resolution' => 'keep_two', 'reason' => 'Two verified people'])
        ->assertHasNoActionErrors()->assertDontSee('Conflicting Synthetic Name');
    expect(DuplicateResolution::query()->sole()->action)->toBe('keep_two');
});

it('requires authority over both people and all their applications', function (string $action) {
    [$a, $b] = m4DuplicatePair();
    $hiddenJob = RecruitmentJob::factory()->create();
    Application::factory()->create(['candidate_id' => $b['candidate']->id, 'recruitment_job_id' => $hiddenJob->id]);
    $recruiter = userWithRole(Role::Recruiter, [$a['job']]);
    expect(app(DuplicateQueue::class)->pairs($recruiter))->toBe([]);
    m4HttpException(fn () => app(ResolveDuplicate::class)->handle($a['candidate']->id, $b['candidate']->id, $action, 'Forged ids', $recruiter, true), 403);
    expect($b['application']->fresh()->candidate_id)->toBe($b['candidate']->id);
})->with(['merge', 'keep_two', 'append_active']);

it('prevents repeat resolutions and stale contact matches and preserves original first receipt', function () {
    [$a, $b] = m4DuplicatePair();
    $owner = userWithRole(Role::Owner);
    $b['candidate']->update(['received_at' => now()->subYear()]);
    $received = $b['candidate']->fresh()->received_at->toDateTimeString();
    $b['candidate']->update(['email_hmac' => str_repeat('b', 64)]);
    m4HttpException(fn () => app(ResolveDuplicate::class)->handle($a['candidate']->id, $b['candidate']->id, 'merge', 'Stale pair', $owner), 403);
    $b['candidate']->update(['email_hmac' => str_repeat('a', 64)]);
    app(ResolveDuplicate::class)->handle($a['candidate']->id, $b['candidate']->id, 'merge', 'Verified', $owner);
    expect($a['candidate']->fresh()->received_at->toDateTimeString())->toBe($received);
    m4HttpException(fn () => app(ResolveDuplicate::class)->handle($a['candidate']->id, $b['candidate']->id, 'merge', 'Replay', $owner), 403);
});

it('does not grant duplicate resolution to hiring managers by default', function (string $action) {
    [$a, $b] = m4DuplicatePair();
    $manager = userWithRole(Role::HiringManager, [$a['job']]);
    m4HttpException(fn () => app(ResolveDuplicate::class)->handle($a['candidate']->id, $b['candidate']->id, $action, 'No grant', $manager, true), 403);
})->with(['merge', 'keep_two', 'append_active']);
