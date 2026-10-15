<?php

namespace App\Infrastructure\Extraction;

final readonly class ExtractedText
{
    public function __construct(
        public string $text,
        public string $extractor,
        public ?int $pageCount = null,
    ) {}
}
