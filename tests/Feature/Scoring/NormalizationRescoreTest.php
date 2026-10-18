<?php

use App\Actions\Criteria\ApproveCriteriaVersion;
use App\Actions\Criteria\StartDraftVersion;
use App\Actions\Scoring\EvaluateApplication;
use App\Domain\Access\Role;
use App\Domain\Ai\EvidenceVerifier;
use App\Jobs\RescoreApplication;
use App\Models\AiExtractionContext;
use App\Models\AiRun;
use App\Models\AiWorkItem;
use App\Models\Score;
use App\Services\Review\ScorePresentation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

it('rescores a stored legacy free-text extraction with a new score preserving old rows quotes MFA and prompt provenance', function () {
    $d = m3Application();
    $actor = userWithRole(Role::Owner);
    $actor->forceFill(['email' => 'owner@example.test'])->save();
    $actor->givePermissionTo('ai.pipeline.manage');
    $d['application']->sources()->first()->update(['external_reference' => 'demo-ai-legacy']);
    $response = json_decode(file_get_contents(base_path('tests/Fixtures/ai/worked-free-text-response-v1.json')), true, 512, JSON_THROW_ON_ERROR);
    $response['experience_relevant_years'][0]['job_id'] = (string) $d['job']->id;
    $facts = (new EvidenceVerifier)->verify($response, m3Text(), $d['document']->id);
    $facts['can_reach_plant_for_shift'] = m3Facts()['can_reach_plant_for_shift'];
    $d['extraction']->update(['fields_json_enc' => json_encode($facts, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
    $legacy = $d['version']->thresholds;
    unset($legacy['normalization']);
    DB::table('criteria_versions')->where('id', $d['version']->id)->update(['thresholds' => json_encode($legacy)]);
    $run = AiRun::query()->create(['document_id' => $d['document']->id, 'purpose' => 'extract', 'driver' => 'cloud', 'model' => 'gemini-3.8-flash', 'prompt_version' => 'extract-v1', 'status' => 'succeeded', 'reserved_cost_egp' => 0, 'cost_egp' => 0, 'request_id' => 'synthetic-legacy-extraction']);
    AiExtractionContext::query()->create(['extraction_id' => $d['extraction']->id, 'ai_run_id' => $run->id, 'mask_map_enc' => '[]', 'job_context_id' => (string) $d['job']->id]);
    $old = Score::factory()->create(['application_id' => $d['application']->id, 'criteria_version_id' => $d['version']->id, 'extraction_id' => $d['extraction']->id, 'score' => 0, 'band' => 'needs_review', 'rules_version' => '1.0']);
    $d['application']->update(['current_score_id' => $old->id]);
    $cipher = DB::table('extractions')->where('id', $d['extraction']->id)->value('fields_json_enc');
    $mfa = DB::table('users')->where('id', $actor->id)->value('app_authentication_secret');
    $workCount = AiWorkItem::query()->count();
    config(['ai-pipeline.driver' => 'cloud', 'queue.connections.ai.driver' => 'sync']);
    m3Settings(['ai.enabled' => true, 'ai.legal_approval_ref' => 'SYNTHETIC-DEMO-ONLY', 'ai.monthly_cap_egp' => 0.0]);
    app()['env'] = 'local';
    $this->artisan('recruit:demo-ai-run', ['--rescore' => true])->assertSuccessful();
    $new = $d['application']->fresh()->currentScore;
    expect($new->id)->not->toBe($old->id)->and($new->score)->toBe('65.00')->and($new->coverage)->toBe('80.00')
        ->and($new->extraction_id)->toBe($d['extraction']->id)->and($new->prompt_version)->toBe('extract-v1')
        ->and($new->model_id)->toBe('gemini-3.8-flash')->and($old->fresh()->score)->toBe('0.00')
        ->and(Score::query()->count())->toBe(2)->and(AiRun::query()->count())->toBe(1)->and(AiWorkItem::query()->count())->toBe($workCount)
        ->and(DB::table('extractions')->where('id', $d['extraction']->id)->value('fields_json_enc'))->toBe($cipher)
        ->and(DB::table('users')->where('id', $actor->id)->value('app_authentication_secret'))->toBe($mfa)
        ->and($d['version']->fresh()->thresholds)->toBe($legacy);
    $result = $new->criterionResults()->orderBy('criterion_id')->first();
    $trace = json_decode($result->normalizations_enc, true, 512, JSON_THROW_ON_ERROR);
    expect($trace[0])->toBe(['field' => 'education_level', 'value' => 'technical_diploma', 'normalized_from' => 'دبلوم فني'])
        ->and(json_decode($result->evidence_enc, true)[0])->toBe($facts['education'][0]['level']['evidence'][0])
        ->and(DB::table('criterion_results')->where('id', $result->id)->value('normalizations_enc'))->not->toContain('دبلوم فني')
        ->and(app(ScorePresentation::class)->data($new)['rows'][0]['normalizations'])->toBe($trace);
    Http::assertNothingSent();
});

it('retries the same rescore request idempotently and appends another explicit request without AI', function () {
    $d = m3Application();
    $old = app(EvaluateApplication::class)->handle($d['application']->id);
    $job = new RescoreApplication($d['application']->id);
    app()->call([$job, 'handle']);
    $new = $d['application']->fresh()->current_score_id;
    app()->call([$job, 'handle']);
    expect(Score::query()->count())->toBe(2)->and($new)->not->toBe($old->id);
    app()->call([new RescoreApplication($d['application']->id), 'handle']);
    expect(Score::query()->count())->toBe(3)->and($old->fresh()->score)->toBe('65.00')->and(AiRun::query()->count())->toBe(0);
    Http::assertNothingSent();
});

it('keeps an approved dictionary stable when settings change and snapshots new aliases on approval', function () {
    $d = m3Application();
    $snapshot = $d['version']->thresholds;
    expect($snapshot['normalization'])->toBe(config('recruitment.comparison_vocabulary'));
    m3Settings(['scoring.synonyms' => ['plc' => ['invented new alias']]]);
    $facts = m3Facts((string) $d['job']->id);
    $facts['skills'][0]['value'] = 'invented new alias';
    $d['extraction']->update(['fields_json_enc' => json_encode($facts)]);
    $score = app(EvaluateApplication::class)->handle($d['application']->id);
    expect($score->criterionResults()->orderBy('criterion_id')->get()[3]->result)->toBe('not_mentioned')
        ->and($d['version']->fresh()->thresholds)->toBe($snapshot);
    $owner = userWithRole(Role::Owner);
    $draft = app(StartDraftVersion::class)->handle($d['job']->fresh(), $owner);
    $approved = app(ApproveCriteriaVersion::class)->handle($draft, $owner);
    $new = app(EvaluateApplication::class)->handle($d['application']->id, $approved->id, explain: false);
    expect($new->criterionResults()->orderBy('criterion_id')->get()[3]->result)->toBe('met')
        ->and($approved->thresholds['synonyms'])->toBe(['plc' => ['invented new alias']])
        ->and($new->extraction_id)->toBe($score->extraction_id)->and($new->id)->not->toBe($score->id);
});
