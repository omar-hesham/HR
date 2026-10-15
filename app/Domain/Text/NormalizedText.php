<?php

namespace App\Domain\Text;

/**
 * Result of TextNormalizer: the normalized text plus an attribution map back to
 * the raw extracted text. Offsets are Unicode code points, never bytes.
 */
final readonly class NormalizedText
{
    /**
     * @param  list<array{0: int, 1: int}>  $offsetRuns  [normalizedStart, rawStart] pairs; inside a run
     *                                                   raw = rawStart + (normalized - normalizedStart).
     */
    public function __construct(
        public string $text,
        public array $offsetRuns,
        public int $presentationFormsReplaced,
    ) {}

    public function length(): int
    {
        return mb_strlen($this->text, 'UTF-8');
    }

    /**
     * Raw-text code point index for a normalized-text code point index.
     */
    public function toRawOffset(int $normalizedIndex): int
    {
        $match = [0, 0];

        foreach ($this->offsetRuns as $run) {
            if ($run[0] > $normalizedIndex) {
                break;
            }

            $match = $run;
        }

        return $match[1] + ($normalizedIndex - $match[0]);
    }
}
