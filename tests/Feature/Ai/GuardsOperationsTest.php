<?php

use App\Actions\Scoring\EvaluateApplication;
use App\Domain\Access\Role;
use App\Domain\Ai\AiDeferred;
use App\Domain\Ai\AiError;
use App\Domain\Ai\AiException;
use App\Domain\Ai\AiProvider;
use App\Domain\Ai\DispatchContext;
use App\Domain\Ai\ExtractionResult;
use App\Domain\Ai\JobContext;
use App\Domain\Ai\MaskedText;
use App\Domain\Ai\Schema;
use App\Domain\Ai\UsageSummary;
use App\Filament\Pages\AiOperations;
use App\Filament\Pages\ManageSettings;
use App\Models\AiRun;
use App\Models\AiWorkItem;
use App\Models\Application;
use App\Models\Document;
use App\Models\DocumentSource;
use App\Models\Extraction;
use App\Models\IntakeMessage;
use App\Models\RecruitmentJob;
use App\Services\Ai\AiOperationsSnapshot;
use App\Services\Ai\AiWorkExecutor;
use App\Services\Ai\CircuitBreaker;
use App\Services\Ai\ConsentRepository;
use App\Services\Ai\CostMeter;
use App\Services\Ai\FakeAiDriver;
use App\Services\Ai\GuardedDatabaseQueue;
use App\Services\Settings\SettingsRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

it('blocks all outbound requests if any cloud prerequisite is absent', function ($missing) {
    $d = m3Application(false);
    m3Settings(['ai.enabled' => true, 'ai.legal_approval_ref' => 'SYNTHETIC-APPROVAL']);
    config(['ai-pipeline.driver' => 'cloud']);
    Http::fake();
    $context = new DispatchContext($d['document']->id, $d['application']->id, candidateId: $d['candidate']->id);
    $actor = userWithRole(Role::Owner);
    $actor->givePermissionTo('ai.pipeline.manage');
    if ($missing !== 'consent') {
        app(ConsentRepository::class)->verify($context, 'SYNTHETIC-CONSENT', $actor);
    }
    if ($missing === 'approval') {
        m3Settings(['ai.legal_approval_ref' => '']);
    }
    if ($missing === 'pipeline') {
        m3Settings(['ai.enabled' => false]);
    }
    $text = new MaskedText('Masked synthetic job text', $context, succeeded: $missing !== 'masking');
    try {
        app(AiProvider::class)->extract($text, new Schema, new JobContext((string) $d['job']->id, 'Synthetic'));
    } catch (AiDeferred|AiException) {
    }
    Http::assertNothingSent();
    expect(AiRun::query()->count())->toBe(0);
})->with(['consent', 'approval', 'pipeline', 'masking']);
it('requires dedicated AI permission even for owners with ordinary settings access', function () {
    $owner = userWithRole(Role::Owner);
    expect(fn () => app(SettingsRepository::class)->update(['ai.legal_approval_ref' => 'UNAUTHORIZED'], $owner))->toThrow(HttpException::class);
    $owner->givePermissionTo('ai.pipeline.manage');
    app(SettingsRepository::class)->update(['ai.legal_approval_ref' => 'SYNTHETIC-APPROVAL'], $owner);
    expect(app(SettingsRepository::class)->string('ai.legal_approval_ref'))->toBe('SYNTHETIC-APPROVAL');
});
it('does not let an ordinary settings Livewire payload alter AI approval or enable the pipeline', function () {
    $owner = userWithRole(Role::Owner);
    $this->actingAs($owner);
    Livewire::test(ManageSettings::class)->set('data.ai.enabled', true)->set('data.ai.legal_approval_ref', 'FORGED')->call('save')->assertHasNoFormErrors();
    expect(app(SettingsRepository::class)->bool('ai.enabled'))->toBeFalse()->and(app(SettingsRepository::class)->string('ai.legal_approval_ref'))->toBe('');
});
it('meters before dispatch, reconciles actuals, and never accounts more than a reservation', function () {
    m3Settings();
    config(['ai-pipeline.driver' => 'cloud']);
    $meter = app(CostMeter::class);
    $run = $meter->reserve('extract', new DispatchContext, 1000);
    expect($run->status)->toBe('reserved')->and((float) $run->getAttribute('reserved_cost_egp'))->toBeGreaterThan(0);
    $result = new ExtractionResult([], 'synthetic-request', 'model', new UsageSummary(20, 30), 10);
    $meter->reconcile($run, $result, null, 10);
    expect((float) $run->getAttribute('cost_egp'))->toBeLessThanOrEqual((float) $run->getAttribute('reserved_cost_egp'));
    $failed = $meter->reserve('extract', new DispatchContext, 1000);
    $meter->reconcile($failed, null, 'Timeout', 100);
    expect($failed->getAttribute('cost_egp'))->toBe($failed->getAttribute('reserved_cost_egp'));
    $over = $meter->reserve('extract', new DispatchContext, 1000);
    $meter->reconcile($over, new ExtractionResult([], 'synthetic', 'model', new UsageSummary(999999, 999999)), null, 1);
    expect($over->getAttribute('error_code'))->toBe('reservation_exceeded')->and((float) $over->getAttribute('cost_egp'))->toBeLessThanOrEqual((float) $over->getAttribute('reserved_cost_egp'));
});
it('keeps budget waits visible with zero requests, attempts, reservations, or outage failures', function () {
    $d = m3Application(false);
    m3Settings(['ai.enabled' => true, 'ai.legal_approval_ref' => 'SYNTHETIC', 'ai.monthly_cap_egp' => 0.0]);
    config(['ai-pipeline.driver' => 'cloud']);
    Http::fake();
    $actor = userWithRole(Role::Owner);
    $actor->givePermissionTo('ai.pipeline.manage');
    app(ConsentRepository::class)->verify(new DispatchContext(candidateId: $d['candidate']->id), 'SYNTHETIC', $actor);
    $work = AiWorkItem::query()->where('purpose', 'extract')->first();
    app(AiWorkExecutor::class)->execute($work->id);
    expect($work->fresh()->reason)->toBe('awaiting_budget')->and($work->fresh()->attempts)->toBe(0)->and(AiRun::query()->count())->toBe(0)->and(Application::query()->count())->toBe(1)->and(app(CircuitBreaker::class)->state('cloud'))->toBe('closed');
    Http::assertNothingSent();
});
it('opens after N provider failures, pauses before pop, leaves intake consumable and leases one recovery trial', function () {
    m3Settings(['ai.enabled' => true, 'ai.breaker_threshold' => 2]);
    $breaker = app(CircuitBreaker::class);
    $breaker->failure('fake');
    expect($breaker->state('fake'))->toBe('closed');
    $breaker->failure('fake');
    expect($breaker->state('fake'))->toBe('open');
    $queue = new GuardedDatabaseQueue(DB::connection(), 'jobs', 'ai', 900);
    $queue->setContainer(app());
    $queue->setConnectionName('ai');
    $queue->push('synthetic-ai-job', [], 'ai');
    $queue->push('synthetic-intake-job', [], 'intake');
    expect($queue->pop('ai'))->toBeNull()->and(DB::table('jobs')->where('queue', 'ai')->value('attempts'))->toBe(0)->and($queue->pop('intake'))->not->toBeNull();
    expect(app(AiOperationsSnapshot::class)->data()['suspended'])->toBeTrue()->and(DB::table('activity_log')->where('event', 'ai.breaker_open')->count())->toBe(1);
    $breaker->recoveredHealth('fake');
    $token = $breaker->acquire('fake');
    expect($token)->not->toBeNull()->and($breaker->canConsume('fake'))->toBeFalse();
    expect(fn () => $breaker->acquire('fake'))->toThrow(AiDeferred::class);
    $breaker->failure('fake');
    expect($breaker->state('fake'))->toBe('open');
    $breaker->recoveredHealth('fake');
    $breaker->acquire('fake');
    $breaker->success('fake');
    expect($breaker->state('fake'))->toBe('closed')->and($breaker->canConsume('fake'))->toBeTrue();
});
it('does not count a breaker wait as a provider attempt or create a run', function () {
    $d = m3Application(false);
    m3Settings(['ai.enabled' => true, 'ai.breaker_threshold' => 1]);
    app(CircuitBreaker::class)->failure('fake');
    $work = AiWorkItem::query()->where('purpose', 'extract')->first();
    app(AiWorkExecutor::class)->execute($work->id);
    app(AiWorkExecutor::class)->execute($work->id);
    expect($work->fresh()->attempts)->toBe(0)->and($work->fresh()->reason)->toBe('awaiting_service')->and(AiRun::query()->count())->toBe(0);
});
it('bounds transient retries to three calls and exposes only safe errors', function () {
    $d = m3Application(false);
    m3Settings();
    $fake = app(FakeAiDriver::class);
    for ($i = 0; $i < 3; $i++) {
        $fake->push('extract', AiError::Timeout);
    } $work = AiWorkItem::query()->where('purpose', 'extract')->first();
    for ($i = 0; $i < 4; $i++) {
        try {
            app(AiWorkExecutor::class)->execute($work->id);
        } catch (AiException $e) {
            expect($e->getMessage())->toBe('Timeout')->and($e->getPrevious())->toBeNull();
        }
    }
    expect($work->fresh()->attempts)->toBe(3)->and($fake->calls)->toHaveCount(3)->and($work->fresh()->state)->toBe('review')->and(AiRun::query()->count())->toBe(3);
});
it('does not retry low confidence or expose CV text in run provenance', function () {
    $d = m3Application(false);
    m3Settings();
    app(FakeAiDriver::class)->push('extract', AiError::LowConfidence);
    $work = AiWorkItem::query()->where('purpose', 'extract')->first();
    app(AiWorkExecutor::class)->execute($work->id);
    expect($work->fresh()->state)->toBe('review')->and($work->fresh()->attempts)->toBe(1)->and(json_encode(DB::table('ai_runs')->first()))->not->toContain('Samir', 'PLC', 'samir@', 'prompt_text');
});
it('shows operations without CV text and enforces operations and management permissions', function () {
    $d = m3Application();
    $score = app(EvaluateApplication::class)->handle($d['application']->id);
    $operator = userWithRole(Role::Operator);
    $this->actingAs($operator)->get(AiOperations::getUrl())->assertOk()->assertDontSee('Samir Hassan')->assertDontSee('Diagnosing PLC faults');
    $this->actingAs(userWithRole(Role::Recruiter))->get(AiOperations::getUrl())->assertForbidden();
    $this->actingAs($operator);
    Livewire::test(AiOperations::class)->call('save')->assertForbidden();
});
it('keeps all 30 applications visible within an hour during an outage', function () {
    Queue::fake();
    Storage::fake('private');
    m3Settings(['ai.enabled' => true, 'ai.breaker_threshold' => 1]);
    app(CircuitBreaker::class)->failure('fake');
    $mailbox = fakeMailbox();
    $job = RecruitmentJob::factory()->create(['title_en' => 'Outage technician']);
    for ($i = 0; $i < 30; $i++) {
        $bytes = file_get_contents(cvFixture('02_en_accountant.pdf'))."\n%synthetic-$i";
        pushEml($mailbox, "outage-$i", buildEml("person$i@example.test", "<outage-$i@example.test>", 'Outage technician', [['filename' => 'cv.pdf', 'bytes' => $bytes, 'type' => 'application/pdf']], now()->toRfc2822String()));
    }
    pollMailbox($mailbox);
    foreach (Document::query()->get() as $document) {
        Extraction::factory()->create(['document_id' => $document->id, 'status' => 'ok', 'normalized_text_enc' => m3Text()]);
    }
    expect(Application::query()->count())->toBe(30)->and(DocumentSource::query()->count())->toBe(30)->and(AiWorkItem::query()->where('reason', 'awaiting_service')->count())->toBe(30)->and(AiRun::query()->count())->toBe(0);
    foreach (IntakeMessage::query()->get() as $message) {
        expect($message->visible_at->diffInSeconds($message->received_at, true))->toBeLessThan(3600);
    }
});
it('classifies ambiguous messages and attachment roles only after consent, with no attachment transmission', function () {
    Queue::fake();
    Storage::fake('private');
    $mailbox = fakeMailbox();
    $eml = buildEml('Samir Hassan <samir@example.test>', '<ambiguous@example.test>', 'Synthetic application', [['filename' => 'unknown.eml', 'bytes' => 'SYNTHETIC_ATTACHMENT_DO_NOT_SEND', 'type' => 'message/rfc822']]);
    pushEml($mailbox, 'ambiguous', $eml);
    pollMailbox($mailbox);
    $message = IntakeMessage::query()->first();
    $work = AiWorkItem::query()->where('purpose', 'classify')->first();
    expect($work)->not->toBeNull()->and($message->outcome)->toBe('needs_review');
    m3Settings();
    app(AiWorkExecutor::class)->execute($work->id);
    expect($work->fresh()->attempts)->toBe(0);
    $actor = userWithRole(Role::Owner);
    $actor->givePermissionTo('ai.pipeline.manage');
    app(ConsentRepository::class)->verify(new DispatchContext(intakeId: $message->id), 'SYNTHETIC', $actor);
    $fake = app(FakeAiDriver::class);
    $fake->push('classify', ['classification' => 'cv', 'confidence' => .91]);
    app(AiWorkExecutor::class)->execute($work->id);
    expect($work->fresh()->state)->toBe('done')->and(json_encode($fake->calls))->not->toContain('SYNTHETIC_ATTACHMENT_DO_NOT_SEND', 'unknown.eml', 'samir@example.test', 'Samir')->and($message->fresh()->outcome)->toBe('needs_review')->and(Document::query()->count())->toBe(0);
});

it('retains the entire reservation when cloud usage is unavailable rather than bypassing the cap', function () {
    m3Settings();
    config(['ai-pipeline.driver' => 'cloud']);
    $meter = app(CostMeter::class);
    $run = $meter->reserve('extract', new DispatchContext, 1000);
    $meter->reconcile($run, new ExtractionResult([], 'synthetic-request', 'model', new UsageSummary), null, 10);
    expect($run->getAttribute('cost_egp'))->toBe($run->getAttribute('reserved_cost_egp'))->and($run->getAttribute('error_code'))->toBe('usage_unavailable')->and($run->status)->toBe('review');
});
