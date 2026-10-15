<?php

namespace App\Domain\Text;

final readonly class QualityThresholds
{
    public function __construct(
        public float $minLetterRatio = 0.60,
        public float $minWordlistRatio = 0.40,
        public float $maxReversedRatio = 0.20,
        public int $minLetters = 40,
    ) {}
}
