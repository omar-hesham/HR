<?php

use App\Actions\Scoring\CorrectField;
use App\Actions\Scoring\EvaluateApplication;
use App\Domain\Access\Role;
use App\Domain\Ai\AiError;
use App\Domain\Ai\AiException;
use App\Domain\Ai\AiProvider;
use App\Domain\Ai\JobContext;
use App\Domain\Ai\MaskedText;
use App\Domain\Ai\Schema;
use App\Filament\Pages\AiOperations;
use App\Jobs\ExtractStructuredFields;
use App\Jobs\NotifyAiOperator;
use App\Jobs\ProbeAiHealth;
use App\Jobs\RescoreApplication;
use App\Jobs\ScoreApplication;
use App\Models\AiRun;
use App\Models\AiWorkItem;
use App\Models\CriterionResult;
use App\Models\Exceptions\ImmutableRecordException;
use App\Models\Extraction;
use App\Models\Score;
use App\Services\Ai\AiWorkCoordinator;
use App\Services\Ai\AiWorkExecutor;
use App\Services\Ai\CircuitBreaker;
use App\Services\Ai\FakeAiDriver;
use App\Services\Ai\RecoverScoring;
use App\Services\Settings\SettingsRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

it('probes outside the paused queue and resumes only after a single half-open trial', function () {
    $d = m3Application(false);
    m3Settings(['ai.enabled' => true, 'ai.breaker_threshold' => 1]);
    $breaker = app(CircuitBreaker::class);
    $breaker->failure('fake');
    $fake = app(FakeAiDriver::class);
    $fake->push('health', ['healthy' => true]);
    $this->travel(61)->seconds();
    app()->call([new ProbeAiHealth, 'handle']);
    expect($breaker->state('fake'))->toBe('half_open');
    Queue::assertPushed(ExtractStructuredFields::class);
    $response = m3Response();
    $response['experience_relevant_years'][0]['job_id'] = (string) $d['job']->id;
    $fake->push('extract', $response);
    $work = AiWorkItem::query()->where('purpose', 'extract')->first();
    app(AiWorkExecutor::class)->execute($work->id);
    expect($breaker->state('fake'))->toBe('closed')->and($work->fresh()->attempts)->toBe(1)->and($fake->calls)->toHaveCount(2);
    $this->travelBack();
});
it('reopens and records an operator alert when the half-open call fails', function () {
    $d = m3Application(false);
    m3Settings(['ai.enabled' => true, 'ai.breaker_threshold' => 1]);
    $breaker = app(CircuitBreaker::class);
    $breaker->failure('fake');
    $breaker->recoveredHealth('fake');
    app(FakeAiDriver::class)->push('extract', AiError::Timeout);
    $work = AiWorkItem::query()->where('purpose', 'extract')->first();
    expect(fn () => app(AiWorkExecutor::class)->execute($work->id))->toThrow(AiException::class, 'Timeout');
    expect($breaker->state('fake'))->toBe('open')->and(DB::table('activity_log')->where('event', 'ai.breaker_open')->count())->toBe(2);
});
it('does not bypass approval on a recovery probe or treat cap exhaustion as a failed health check', function () {
    m3Settings(['ai.enabled' => true, 'ai.monthly_cap_egp' => 0.0, 'ai.legal_approval_ref' => 'SYNTHETIC', 'ai.breaker_threshold' => 1]);
    config(['ai-pipeline.driver' => 'cloud']);
    $breaker = app(CircuitBreaker::class);
    $breaker->failure('cloud');
    $this->travel(61)->seconds();
    Http::fake();
    app()->call([new ProbeAiHealth, 'handle']);
    expect($breaker->state('cloud'))->toBe('open')->and(DB::table('ai_provider_states')->where('provider', 'cloud')->value('failures'))->toBe(1)->and(AiRun::query()->count())->toBe(0);
    Http::assertNothingSent();
    $this->travelBack();
});
it('makes exactly three SDK wire attempts despite Retry-After and preserves their request ids', function () {
    $d = m3Application(false);
    m3Settings();
    config(['ai-pipeline.driver' => 'local', 'ai.providers.recruit_local.url' => 'http://127.0.0.1:8080/v1']);
    $ids = [];
    Http::fake(function ($request) use (&$ids) {
        $id = $request->header('X-Request-ID')[0];
        $ids[] = $id;
        expect(AiRun::query()->where('request_id', $id)->where('status', 'reserved')->exists())->toBeTrue();

        return Http::response(['error' => ['message' => 'Synthetic rate limit']], 429, ['Retry-After' => '0']);
    });
    $work = AiWorkItem::query()->where('purpose', 'extract')->first();
    for ($i = 0; $i < 4; $i++) {
        try {
            app(AiWorkExecutor::class)->execute($work->id);
        } catch (AiException $e) {
            expect($e->error)->toBe(AiError::RateLimited);
        }
    }
    Http::assertSentCount(3);
    expect(AiRun::query()->count())->toBe(3)->and($work->fresh()->attempts)->toBe(3)->and($ids)->toBe(AiRun::query()->orderBy('id')->pluck('request_id')->all());
});
it('maps authentication errors to non-retryable safe errors', function () {
    m3Settings();
    config(['ai-pipeline.driver' => 'local', 'ai.providers.recruit_local.url' => 'http://127.0.0.1:8080/v1']);
    Http::fake(['*' => Http::response(['error' => ['message' => 'DO_NOT_LEAK_CV_OR_SECRET']], 401)]);
    try {
        app(AiProvider::class)->extract(new MaskedText('masked', succeeded: true), new Schema, new JobContext('1', 'Synthetic'));
        test()->fail('Expected authentication failure');
    } catch (AiException $e) {
        expect($e->error)->toBe(AiError::AuthenticationFailed)->and($e->error->retryable())->toBeFalse()->and($e->getMessage())->not->toContain('DO_NOT_LEAK')->and($e->getPrevious())->toBeNull();
    }Http::assertSentCount(1);
});
it('keeps unknown timeout charges reserved and never resets durable attempts after a crashed worker', function () {
    $d = m3Application(false);
    m3Settings();
    $work = AiWorkItem::query()->where('purpose', 'extract')->first();
    $work->forceFill(['attempts' => 3, 'state' => 'queued', 'queued_at' => now()->subHour()])->save();
    app(AiWorkCoordinator::class)->recover();
    expect($work->fresh()->state)->toBe('review')->and($work->fresh()->attempts)->toBe(3)->and(app(FakeAiDriver::class)->calls)->toHaveCount(0);
});
it('recovers a lost scoring dispatch for a changed correction without re-extracting', function () {
    $d = m3Application();
    $old = app(EvaluateApplication::class)->handle($d['application']->id);
    $actor = userWithRole(Role::Owner);
    app(CorrectField::class)->handle($d['application'], $d['extraction'], 'certifications.0', ['value' => 'safety_training', 'confidence' => 1, 'evidence' => [['quote' => 'Electrical safety training']]], $actor, 'Synthetic correction');
    Queue::fake();
    app(RecoverScoring::class)->dispatch();
    Queue::assertPushed(RescoreApplication::class, fn ($job) => $job->applicationId === $d['application']->id);
    expect(Extraction::query()->count())->toBe(1)->and(Score::query()->count())->toBe(1);
});
it('writes no partial score when a criterion insert fails', function () {
    $d = m3Application();
    CriterionResult::creating(function ($result) {
        if ($result->criterion_id % 2 === 0) {
            throw new RuntimeException('synthetic_insert_failure');
        }
    });
    expect(fn () => app(EvaluateApplication::class)->handle($d['application']->id))->toThrow(RuntimeException::class, 'synthetic_insert_failure');
    expect(Score::query()->count())->toBe(0)->and(DB::table('criterion_results')->count())->toBe(0)->and($d['application']->fresh()->current_score_id)->toBeNull();
});
it('prevents modification of stored evaluation history', function () {
    $d = m3Application();
    $score = app(EvaluateApplication::class)->handle($d['application']->id);
    expect(fn () => $score->forceFill(['score' => 100])->save())->toThrow(ImmutableRecordException::class);
    $criterion = $score->criterionResults()->first();
    expect(fn () => $criterion->forceFill(['result' => 'not_met'])->save())->toThrow(ImmutableRecordException::class);
});
it('allows the dedicated permission to manage and persist AI controls through the operations page', function () {
    $owner = userWithRole(Role::Owner);
    $owner->givePermissionTo('ai.pipeline.manage');
    $this->actingAs($owner);
    Livewire::test(AiOperations::class)->set('data.ai.legal_approval_ref', 'SYNTHETIC-APPROVAL')->set('data.ai.monthly_cap_egp', 250)->call('save')->assertHasNoFormErrors();
    expect(app(SettingsRepository::class)->float('ai.monthly_cap_egp'))->toBe(250.0)->and(app(SettingsRepository::class)->string('ai.legal_approval_ref'))->toBe('SYNTHETIC-APPROVAL');
});

it('counts failed closed-state health checks toward the provider breaker', function () {
    m3Settings(['ai.enabled' => true, 'ai.breaker_threshold' => 2]);
    $fake = app(FakeAiDriver::class);
    $fake->push('health', ['healthy' => false]);
    $fake->push('health', ['healthy' => false]);
    app()->call([new ProbeAiHealth, 'handle']);
    expect(app(CircuitBreaker::class)->state('fake'))->toBe('closed');
    $this->travel(61)->seconds();
    app()->call([new ProbeAiHealth, 'handle']);
    expect(app(CircuitBreaker::class)->state('fake'))->toBe('open');
    $this->travelBack();
});
it('does not even enqueue rescoring for a true duplicate with a valid current score', function () {
    $d = m3Application();
    app(EvaluateApplication::class)->handle($d['application']->id);
    Queue::fake();
    app(AiWorkCoordinator::class)->forApplication($d['application']->id);
    Queue::assertNotPushed(ScoreApplication::class);
    expect(Score::query()->count())->toBe(1);
});
it('queues a safe operator alert independently from the suspended queue', function () {
    Queue::fake();
    m3Settings(['ai.breaker_threshold' => 1]);
    app(CircuitBreaker::class)->failure('fake');
    Queue::assertPushed(NotifyAiOperator::class, fn ($job) => $job->queue === 'default' && $job->provider === 'fake');
});
