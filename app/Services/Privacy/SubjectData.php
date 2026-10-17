<?php

namespace App\Services\Privacy;

use App\Infrastructure\Storage\EncryptedDocumentStore;
use App\Models\Application;
use App\Models\Candidate;
use App\Models\Document;
use App\Models\FieldCorrection;

final class SubjectData
{
    /** @return array<string,mixed> */
    public function payload(Candidate $candidate): array
    {
        $ids = app(IdentityGroup::class)->ids($candidate->id);
        $applications = Application::query()->whereIn('candidate_id', $ids)->with(['scores.criterionResults', 'scores.overrides', 'notes', 'statusEvents', 'sources'])->get();
        $documents = Document::query()->whereIn('candidate_id', $ids)->with('extractions')->get();
        $files = [];
        foreach ($documents as $document) {
            $files[] = ['document' => $document->toArray(), 'original_base64' => $document->path === null ? null : base64_encode(app(EncryptedDocumentStore::class)->get($document->path))];
        }

        return ['subjects' => Candidate::query()->whereIn('id', $ids)->get()->toArray(), 'applications' => $applications->toArray(), 'documents' => $files,
            'corrections' => FieldCorrection::query()->whereIn('application_id', $applications->modelKeys())->get()->toArray(), 'mailbox_read_only' => true, 'provider_deletion_factory_responsibility' => true];
    }
}
