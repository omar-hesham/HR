<?php

namespace Database\Factories;

use App\Models\Document;
use App\Models\Extraction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Extraction>
 */
class ExtractionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'document_id' => Document::factory(),
            'extractor' => 'pdftotext',
            'extractor_version' => (string) config('recruitment.extraction.extractor_version'),
            'status' => Extraction::STATUS_OK,
            'raw_text_enc' => 'Synthetic CV text',
            'normalized_text_enc' => 'Synthetic CV text',
            'offset_map_enc' => '[[0,0]]',
            'char_count' => 17,
        ];
    }
}
