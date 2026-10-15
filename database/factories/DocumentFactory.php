<?php

namespace Database\Factories;

use App\Domain\Documents\DocumentKind;
use App\Domain\Documents\DocumentStatus;
use App\Models\Candidate;
use App\Models\Document;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Metadata only; tests that need a stored file go through UploadDocuments.
 *
 * @extends Factory<Document>
 */
class DocumentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'candidate_id' => Candidate::factory(),
            'path' => null,
            'sha256' => hash('sha256', Str::random(40)),
            'mime' => 'application/pdf',
            'extension' => 'pdf',
            'size' => 1024,
            'kind' => DocumentKind::Cv,
            'status' => DocumentStatus::Pending,
            'encrypted' => true,
        ];
    }
}
