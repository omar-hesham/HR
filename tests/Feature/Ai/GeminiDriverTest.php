<?php

use App\Domain\Access\Role;
use App\Domain\Ai\DispatchContext;
use App\Models\AiRun;
use App\Services\Ai\ConsentRepository;
use App\Services\Ai\StructuredExtraction;
use Illuminate\Support\Facades\Http;

it('sends masked Gemini JSON-mode requests and meters returned token usage through existing guards', function () {
    $fixture = m3Application(false);
    $actor = userWithRole(Role::Owner);
    $actor->givePermissionTo('ai.pipeline.manage');
    app(ConsentRepository::class)->verify(new DispatchContext(candidateId: $fixture['candidate']->id), 'SYNTHETIC-DEMO-ONLY', $actor);
    m3Settings(['ai.enabled' => true, 'ai.legal_approval_ref' => 'SYNTHETIC-DEMO-ONLY']);
    config(['ai-pipeline.driver' => 'cloud', 'ai-pipeline.cloud_model' => 'gemini-3.8-flash',
        'ai.providers.recruit_cloud.url' => 'https://generativelanguage.googleapis.com/v1beta/openai/']);
    $previousKey = getenv('AI_CLOUD_API_KEY');
    putenv('AI_CLOUD_API_KEY=synthetic-process-token');
    $response = m3Response();
    $response['experience_relevant_years'][0]['job_id'] = (string) $fixture['job']->id;
    Http::fake(['*' => Http::response(['id' => 'synthetic-gemini-response', 'model' => 'gemini-3.8-flash',
        'choices' => [['message' => ['role' => 'assistant', 'content' => json_encode($response)], 'finish_reason' => 'stop']],
        'usage' => ['prompt_tokens' => 123, 'completion_tokens' => 456, 'completion_tokens_details' => ['reasoning_tokens' => 100]]])]);
    try {
        $extraction = app(StructuredExtraction::class)->run($fixture['document']->id, $fixture['application']->id);
        $fields = json_decode($extraction->fields_json_enc, true);
        expect($fields['education'][0]['level']['source'])->toBe('model');
        Http::assertSent(function ($request) {
            expect($request->url())->toBe('https://generativelanguage.googleapis.com/v1beta/openai/chat/completions')
                ->and($request['model'])->toBe('gemini-3.8-flash')->and($request['response_format'])->toBe(['type' => 'json_object'])
                ->and($request->body())->toContain('controlled_vocabulary')->toContain('qualification_levels')->toContain('technical_diploma')->toContain('schematics')->toContain('Canonical values are comparison labels only')->not->toContain('Samir Hassan')->not->toContain('@example.test');

            return true;
        });
        $run = AiRun::query()->sole();
        expect($run->status)->toBe('succeeded')->and($run->input_tokens)->toBe(123)->and($run->output_tokens)->toBe(456)
            ->and($run->prompt_version)->toBe('extract-v2')->and((float) $run->cost_egp)->toBe(.1948)->and($run->model)->toBe('gemini-3.8-flash');
    } finally {
        putenv($previousKey === false ? 'AI_CLOUD_API_KEY' : 'AI_CLOUD_API_KEY='.$previousKey);
    }
});
