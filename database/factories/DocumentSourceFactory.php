<?php

namespace Database\Factories;

use App\Domain\Documents\Disposition;
use App\Domain\Documents\DocumentKind;
use App\Domain\Documents\SourceChannel;
use App\Models\DocumentSource;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<DocumentSource>
 */
class DocumentSourceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $batch = (string) Str::uuid();

        return [
            'source' => SourceChannel::Upload,
            'attachment_key' => $batch.':0',
            'attachment_kind' => DocumentKind::Cv,
            'disposition' => Disposition::Pending,
            'original_filename_enc' => 'synthetic-cv.pdf',
            'reported_mime' => 'application/pdf',
            'size' => 1024,
            'upload_batch' => $batch,
        ];
    }
}
