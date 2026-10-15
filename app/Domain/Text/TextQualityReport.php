<?php

namespace App\Domain\Text;

use App\Domain\Documents\ReviewReason;

/**
 * Measured values and the decision of TextQualityChecker. Contains no CV text,
 * so it may be stored unencrypted and shown to reviewers.
 */
final readonly class TextQualityReport
{
    /**
     * @param  list<string>  $failures  threshold names that failed
     */
    public function __construct(
        public int $letters,
        public int $nonWhitespace,
        public int $unmappedGlyphs,
        public int $alphaTokens,
        public float $letterRatio,
        public float $wordlistRatio,
        public float $reversedRatio,
        public array $failures,
        public ?ReviewReason $reason,
        public float $score,
    ) {}

    public function passed(): bool
    {
        return $this->reason === null;
    }

    /**
     * @return array<string, int|float|list<string>|string|null>
     */
    public function toArray(): array
    {
        return [
            'letters' => $this->letters,
            'non_whitespace' => $this->nonWhitespace,
            'unmapped_glyphs' => $this->unmappedGlyphs,
            'alpha_tokens' => $this->alphaTokens,
            'letter_ratio' => round($this->letterRatio, 4),
            'wordlist_ratio' => round($this->wordlistRatio, 4),
            'reversed_ratio' => round($this->reversedRatio, 4),
            'failures' => $this->failures,
            'reason' => $this->reason?->value,
            'score' => round($this->score, 4),
        ];
    }
}
