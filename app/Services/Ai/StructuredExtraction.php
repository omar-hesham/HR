<?php

namespace App\Services\Ai;

use App\Domain\Ai\AiProvider;
use App\Domain\Ai\DispatchContext;
use App\Domain\Ai\EvidenceVerifier;
use App\Domain\Ai\FactSupportVerifier;
use App\Domain\Ai\JobContext;
use App\Domain\Ai\Masker;
use App\Domain\Ai\Schema;
use App\Domain\Ai\VerificationSummary;
use App\Domain\Applications\ApplicationStatus;
use App\Domain\Documents\DocumentKind;
use App\Domain\Text\SearchNormalizer;
use App\Infrastructure\Storage\EncryptedDocumentStore;
use App\Models\AiExtractionContext;
use App\Models\Application;
use App\Models\Document;
use App\Models\Extraction;
use App\Services\Settings\SettingsRepository;
use Illuminate\Support\Facades\DB;

final readonly class StructuredExtraction
{
    public function __construct(private AiProvider $provider, private Masker $masker, private DeterministicIdentity $identity, private SettingsRepository $settings) {}

    public function run(int $documentId, ?int $applicationId, ?int $workItemId = null): ?Extraction
    {
        $document = Document::query()->find($documentId);
        $application = Application::query()->find($applicationId);
        $extraction = $document?->extractions()->where('status', Extraction::STATUS_OK)->latest('id')->first();
        if ($document === null || $document->kind !== DocumentKind::Cv || $document->path === null || ! app(EncryptedDocumentStore::class)->exists($document->path) || $extraction === null || $application === null || $application->status === ApplicationStatus::Deleted) {
            return null;
        }
        if ($extraction->schema_version === Schema::VERSION && $extraction->fields_json_enc !== null) {
            return $extraction;
        }
        $text = $extraction->normalizedText();
        if ($text === null || $text === '') {
            return null;
        }
        $identityContext = app(SourceIdentityContext::class)->forDocument($document);
        $filename = $identityContext['filename'];
        $sender = $identityContext['sender'];
        $context = new DispatchContext($documentId, $applicationId, candidateId: $application->candidate_id, workItemId: $workItemId);
        $masked = $this->masker->mask($text, $context, sender: $sender, filename: $filename);
        AiExtractionContext::query()->updateOrCreate(['extraction_id' => $extraction->id], ['mask_map_enc' => json_encode($masked->substitutions, JSON_THROW_ON_ERROR), 'job_context_id' => (string) $application->recruitment_job_id]);
        $local = $this->identity->extract($text, $documentId, sender: $sender, filename: $filename);
        $military = $this->settings->bool('ai.military_enabled') && trim($this->settings->string('ai.military_legal_ref')) !== '';
        $response = $this->provider->extract($masked, new Schema, new JobContext((string) $application->recruitment_job_id, $application->job->title_en ?? '', $military));
        $payload = $response->data;
        $payload['name'] = $local['name'];
        $payload['contacts'] = $local['contacts'];
        if (! $military) {
            $payload['military_status'] = ['value' => null, 'confidence' => 0, 'evidence' => []];
        }
        $payload = (new EvidenceVerifier)->verify($payload, $text, $documentId, $masked->substitutions);
        $payload = (new FactSupportVerifier)->verify($payload);
        $verificationError = (new VerificationSummary)->firstError($payload);

        return DB::transaction(function () use ($document, $application, $extraction, $payload, $local, $verificationError): ?Extraction {
            $locked = Extraction::query()->whereKey($extraction->id)->lockForUpdate()->first();
            $app = Application::query()->whereKey($application->id)->lockForUpdate()->first();
            if ($locked === null || $app === null || $app->status === ApplicationStatus::Deleted || ! Document::query()->whereKey($document->id)->whereNotNull('path')->exists()) {
                return null;
            }
            if ($locked->fields_json_enc !== null && $locked->schema_version === Schema::VERSION) {
                return $locked;
            }
            $locked->forceFill(['fields_json_enc' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'schema_version' => Schema::VERSION, 'error_code' => $verificationError])->save();
            AiExtractionContext::query()->where('extraction_id', $locked->id)->update(['ai_run_id' => $this->provider instanceof GuardedAiProvider ? $this->provider->lastRunId : null]);
            $candidate = $document->candidate;
            if ($candidate !== null && $candidate->active_document_id === $document->id) {
                $candidate->forceFill(['name' => $local['display_name'], 'email' => $local['contacts']['email']['value'], 'email_hmac' => $local['contacts']['email']['value'] === null ? null : hash_hmac('sha256', mb_strtolower($local['contacts']['email']['value']), (string) config('recruitment.hash.dedup_key')), 'phone_hmac' => $local['contacts']['phone']['value'] === null ? null : hash_hmac('sha256', $local['contacts']['phone']['value'], (string) config('recruitment.hash.dedup_key')), 'phone' => $local['contacts']['phone']['value'], 'phone_original' => $local['phone_original'], 'search_normalized' => (new SearchNormalizer)->normalize(implode(' ', array_filter([$local['display_name'], $local['contacts']['email']['value'], $local['contacts']['phone']['value']])))])->save();
            }

            return $locked;
        });
    }
}
