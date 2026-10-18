<?php

use App\Domain\Ai\EvidenceVerifier;
use App\Domain\Criteria\CriteriaVersionStatus;
use App\Domain\Criteria\CriterionData;
use App\Domain\Documents\DocumentStatus;
use App\Infrastructure\Storage\EncryptedDocumentStore;
use App\Models\Application;
use App\Models\Candidate;
use App\Models\CriteriaVersion;
use App\Models\Criterion;
use App\Models\Document;
use App\Models\DocumentSource;
use App\Models\Extraction;
use App\Models\RecruitmentJob;
use App\Models\Setting;
use App\Services\Settings\SettingsRepository;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

function m3Response(): array
{
    return json_decode(file_get_contents(base_path('tests/Fixtures/ai/worked-response-v1.json')), true, 512, JSON_THROW_ON_ERROR);
}
function m3Text(): string
{
    return file_get_contents(base_path('tests/Fixtures/ai/worked-text.txt'));
}
function m3Snapshot(): array
{
    return ['strong' => 70, 'review' => 40, 'confidence' => .8, 'education_levels' => config('recruitment.qualification_levels'), 'synonyms' => config('ai-rules.synonyms'), 'normalization' => config('recruitment.comparison_vocabulary'), 'negative_markers' => config('ai-rules.negative_markers'), 'affirmative_statements' => config('ai-rules.affirmative_statements'), 'negative_shift_statements' => config('ai-rules.negative_shift_statements')];
}
function m3Facts(string $jobId = '1'): array
{
    $response = m3Response();
    $response['experience_relevant_years'][0]['job_id'] = $jobId;
    $facts = (new EvidenceVerifier)->verify($response, m3Text(), 1);
    $facts['can_reach_plant_for_shift'] = ['value' => true, 'confidence' => 1, 'evidence' => [['quote' => 'I accept work at the specified location']], 'source' => 'human_answer'];

    return $facts;
}
function m3Criteria(): array
{
    return array_map(fn ($c) => CriterionData::fromArray(['label_ar' => $c[0], 'label_en' => $c[0], 'type' => $c[1], 'field' => $c[2], 'operator' => $c[3], 'target' => $c[4], 'weight' => $c[5], 'justification' => 'Illustrative synthetic criterion', 'legal_basis_note' => $c[1] === 'disqualifier' ? 'Illustrative explicit site refusal' : null]), [
        ['C1', 'must_have', 'education_level', 'gte', ['value' => 'technical_diploma', 'field' => 'electrical'], null],
        ['C2', 'must_have', 'experience_relevant_years', 'gte', ['value' => 2], null],
        ['C3', 'must_have', 'shift_availability', 'is_explicitly_true', [], null],
        ['C4', 'nice_to_have', 'skills', 'contains_any', ['values' => ['plc']], 40],
        ['C5', 'nice_to_have', 'skills', 'contains_any', ['values' => ['schematics']], 25],
        ['C6', 'nice_to_have', 'certifications', 'contains_any', ['values' => ['safety_training']], 20],
        ['C7', 'nice_to_have', 'skills', 'contains_any', ['values' => ['wire_drawing']], 15],
        ['C8', 'disqualifier', 'can_reach_plant_for_shift', 'is_explicitly_true', ['value' => false], null],
    ]);
}
function m3Settings(array $settings = ['ai.enabled' => true]): void
{
    foreach ($settings as $key => $value) {
        Setting::query()->updateOrCreate(['key' => $key], ['value_json' => $value, 'version' => 1]);
    }
    app(SettingsRepository::class)->flush();
}
function m3Application(bool $withFields = true): array
{
    Queue::fake();
    if (! app()->bound('m3.storage.faked')) {
        Storage::fake('private');
        app()->instance('m3.storage.faked', true);
    }
    $job = RecruitmentJob::factory()->create();
    $version = CriteriaVersion::factory()->create(['recruitment_job_id' => $job->id, 'status' => CriteriaVersionStatus::Draft, 'thresholds' => m3Snapshot()]);
    foreach (m3Criteria() as $i => $criterion) {
        Criterion::query()->create(['criteria_version_id' => $version->id, 'type' => $criterion->type, 'field' => $criterion->field, 'operator' => $criterion->operator, 'target_json' => $criterion->target, 'weight' => $criterion->weight, 'label_ar' => $criterion->labelAr, 'label_en' => $criterion->labelEn, 'justification' => $criterion->justification, 'legal_basis_note' => $criterion->legalBasisNote, 'sort' => $i]);
    }
    $version->forceFill(['status' => CriteriaVersionStatus::Approved, 'approved_at' => now()])->save();
    $job->forceFill(['current_criteria_version_id' => $version->id])->save();
    $candidate = Candidate::factory()->create(['name' => 'Samir Hassan']);
    $document = Document::factory()->create(['candidate_id' => $candidate->id, 'status' => DocumentStatus::Extracted]);
    $document->forceFill(['path' => app(EncryptedDocumentStore::class)->put($candidate->uuid, $document->uuid, '%PDF-synthetic')])->save();
    $candidate->forceFill(['active_document_id' => $document->id])->save();
    $application = Application::factory()->create(['candidate_id' => $candidate->id, 'recruitment_job_id' => $job->id]);
    DocumentSource::factory()->create(['application_id' => $application->id, 'document_id' => $document->id, 'original_filename_enc' => 'Samir_Hassan_CV.pdf']);
    $extraction = Extraction::factory()->create(['document_id' => $document->id, 'status' => Extraction::STATUS_OK, 'normalized_text_enc' => m3Text(), 'raw_text_enc' => m3Text(), 'schema_version' => $withFields ? '1.0' : null, 'fields_json_enc' => $withFields ? json_encode(m3Facts((string) $job->id), JSON_THROW_ON_ERROR) : null]);

    return compact('job', 'version', 'candidate', 'document', 'application', 'extraction');
}
