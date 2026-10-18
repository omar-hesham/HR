<?php

use App\Domain\Access\Role;
use App\Domain\Ai\DispatchContext;
use App\Domain\Ai\EvidenceVerifier;
use App\Models\AiRun;
use App\Models\AiWorkItem;
use App\Models\Application;
use App\Models\Candidate;
use App\Models\CriterionResult;
use App\Models\Document;
use App\Models\Extraction;
use App\Models\IntakeMessage;
use App\Models\Score;
use App\Models\User;
use App\Services\Ai\ConsentRepository;
use Database\Seeders\DemoJobsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

function demoAiActor(): User
{
    $actor = userWithRole(Role::Owner);
    $actor->forceFill(['email' => 'owner@example.test'])->save();
    $actor->givePermissionTo('ai.pipeline.manage');
    app(DemoJobsSeeder::class)->run();
    app()['env'] = 'local';

    return $actor;
}

it('refuses outside local and refuses an actor without the existing AI consent permission', function () {
    Storage::fake('private');
    $this->artisan('recruit:demo-ai-run')->assertFailed();
    app()['env'] = 'local';
    userWithRole(Role::Owner)->update(['email' => 'owner@example.test']);
    $this->artisan('recruit:demo-ai-run')->assertFailed();
    expect(Document::query()->count())->toBe(0);
    Http::assertNothingSent();
});

it('imports only raw documents and records synthetic consent before queue processing', function () {
    Storage::fake('private');
    Queue::fake();
    $actor = demoAiActor();
    $mfa = DB::table('users')->where('id', $actor->id)->value('app_authentication_secret');
    $this->artisan('recruit:demo-ai-run')->assertSuccessful();
    expect(Document::query()->count())->toBe(12)->and(Extraction::query()->count())->toBe(0)
        ->and(Score::query()->count())->toBe(0)->and(AiRun::query()->count())->toBe(0);
    foreach (Candidate::query()->get() as $candidate) {
        expect(app(ConsentRepository::class)->verified(new DispatchContext(candidateId: $candidate->id)))->toBeTrue();
    }
    expect(DB::table('users')->where('id', $actor->id)->value('app_authentication_secret'))->toBe($mfa);
    Http::assertNothingSent();
});

it('completes extraction and scoring from raw synthetic DOCX through the fake driver without network', function () {
    Storage::fake('private');
    demoAiActor();
    m3Settings(['ai.enabled' => true]);
    config(['queue.connections.ai.driver' => 'sync']);
    $this->artisan('recruit:demo-ai-run')->assertSuccessful();
    expect(Extraction::query()->whereNotNull('fields_json_enc')->count())->toBe(12)
        ->and(Score::query()->count())->toBe(12)->and(AiRun::query()->where('status', 'failed')->count())->toBe(0)
        ->and(AiRun::query()->where('purpose', 'extract')->where('status', 'succeeded')->count())->toBe(12)
        ->and(CriterionResult::query()->where('source', 'model')->count())->toBeGreaterThan(0)
        ->and(Application::query()->where('status', 'rejected')->count())->toBe(0);
    $this->artisan('recruit:demo-ai-run', ['--summary' => true])->assertSuccessful();
    expect(Document::query()->count())->toBe(12);
    Http::assertNothingSent();
});

it('keeps disabled AI waiting and optionally polls only the synthetic fixture mailbox', function () {
    Storage::fake('private');
    demoAiActor();
    $this->artisan('recruit:demo-ai-run', ['--with-mail' => true])->assertSuccessful();
    expect(IntakeMessage::query()->count())->toBeGreaterThan(0)
        ->and(AiRun::query()->count())->toBe(0)->and(Score::query()->count())->toBe(0)
        ->and(AiWorkItem::query()->where('reason', 'awaiting_service')->count())->toBeGreaterThan(0);
    foreach (IntakeMessage::query()->get() as $message) {
        expect(app(ConsentRepository::class)->verified(new DispatchContext(intakeId: $message->id)))->toBeTrue();
    }
    Http::assertNothingSent();
});

it('rescores all twelve stored free-text demo responses to the canonical recording without additional AI work', function () {
    Storage::fake('private');
    demoAiActor();
    m3Settings(['ai.enabled' => true]);
    config(['queue.connections.ai.driver' => 'sync']);
    $this->artisan('recruit:demo-ai-run')->assertSuccessful();
    $expected = Application::query()->with('currentScore')->get()->mapWithKeys(fn ($application) => [$application->id => $application->currentScore->only(['score', 'coverage', 'band'])])->all();
    expect(Score::query()->count())->toBe(12)
        ->and(array_values(array_unique(array_values($expected), SORT_REGULAR)))->toBe([['score' => '85.00', 'coverage' => '85.00', 'band' => 'strong_match']]);
    $template = json_decode(file_get_contents(base_path('tests/Fixtures/ai/demo-free-text-response-v1.json')), true, 512, JSON_THROW_ON_ERROR);
    foreach (Application::query()->get() as $application) {
        $extraction = $application->candidate->activeDocument->latestExtraction;
        $response = $template;
        $response['experience_relevant_years'][0]['job_id'] = (string) $application->recruitment_job_id;
        $facts = (new EvidenceVerifier)->verify($response, $extraction->normalizedText(), $extraction->document_id);
        $extraction->update(['fields_json_enc' => json_encode($facts, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
    }
    $runs = AiRun::query()->count();
    $work = AiWorkItem::query()->count();
    $fields = DB::table('extractions')->pluck('fields_json_enc', 'id')->all();
    config(['ai-pipeline.driver' => 'cloud']);
    $this->artisan('recruit:demo-ai-run', ['--rescore' => true])
        ->expectsOutputToContain(__('brain.rescored', ['created' => 12, 'skipped' => 0]))->assertSuccessful();
    foreach (Application::query()->with('currentScore')->get() as $application) {
        expect($application->currentScore->only(['score', 'coverage', 'band']))->toBe($expected[$application->id]);
    }
    expect(Score::query()->count())->toBe(24)->and(Document::query()->count())->toBe(12)
        ->and(AiRun::query()->count())->toBe($runs)->and(AiWorkItem::query()->count())->toBe($work)
        ->and(DB::table('extractions')->pluck('fields_json_enc', 'id')->all())->toBe($fields);
    Http::assertNothingSent();
});

it('refuses rescore outside local and rejects worker or import options without side effects', function () {
    $this->artisan('recruit:demo-ai-run', ['--rescore' => true])->assertFailed();
    app()['env'] = 'local';
    $this->artisan('recruit:demo-ai-run', ['--rescore' => true])->assertFailed();
    foreach (['--work', '--with-mail'] as $option) {
        $this->artisan('recruit:demo-ai-run', ['--rescore' => true, $option => true])->assertFailed();
    }
    expect(Document::query()->count())->toBe(0)->and(Score::query()->count())->toBe(0)->and(AiRun::query()->count())->toBe(0);
    Http::assertNothingSent();
});
