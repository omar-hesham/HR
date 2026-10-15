<?php

namespace App\Domain\Text;

use App\Domain\Documents\ReviewReason;

/**
 * M2-P0-04 text-quality gate, run on NFKC-normalized text:
 *  - letter ratio   = Arabic+Latin letters / non-whitespace characters      (>= min)
 *  - wordlist ratio = alphabetic tokens found in the wordlist / alpha tokens (>= min)
 *  - reversed ratio = unknown tokens whose reverse is known / alpha tokens  (<= max)
 * An alphabetic token has at least two letters; digits never count as tokens.
 * Failing any threshold, or too few letters to measure, routes to review.
 */
final class TextQualityChecker
{
    private readonly ReversedTextDetector $reversed;

    public function __construct(private readonly Wordlist $wordlist)
    {
        $this->reversed = new ReversedTextDetector($wordlist);
    }

    public function check(string $normalizedText, QualityThresholds $thresholds): TextQualityReport
    {
        $nonWhitespace = preg_match_all('/\S/u', $normalizedText);
        $letters = preg_match_all('/(?=\p{L})[\p{Arabic}\p{Latin}]/u', $normalizedText);
        $nonWhitespace = $nonWhitespace === false ? 0 : $nonWhitespace;
        $letters = $letters === false ? 0 : $letters;
        // Glyphs the PDF could not map back to Unicode (private-use code points). Reported, not repaired.
        $unmapped = preg_match_all('/\p{Co}/u', $normalizedText);
        $unmapped = $unmapped === false ? 0 : $unmapped;

        $tokens = [];
        preg_match_all('/[\p{L}\p{M}]+/u', $normalizedText, $matches);

        foreach ($matches[0] as $raw) {
            $token = SearchNormalizer::normalize($raw);

            if (preg_match_all('/\p{L}/u', $token) >= 2) {
                $tokens[] = $token;
            }
        }

        $alphaTokens = count($tokens);
        $known = 0;
        $reversed = 0;

        foreach ($tokens as $token) {
            if ($this->wordlist->contains($token)) {
                $known++;
            } elseif ($this->reversed->isSuspected($token)) {
                $reversed++;
            }
        }

        $letterRatio = $nonWhitespace > 0 ? $letters / $nonWhitespace : 0.0;
        $wordlistRatio = $alphaTokens > 0 ? $known / $alphaTokens : 0.0;
        $reversedRatio = $alphaTokens > 0 ? $reversed / $alphaTokens : 0.0;

        $failures = [];

        if ($letters < $thresholds->minLetters) {
            $failures[] = 'min_letters';
        }

        if ($letterRatio < $thresholds->minLetterRatio) {
            $failures[] = 'min_letter_ratio';
        }

        if ($wordlistRatio < $thresholds->minWordlistRatio) {
            $failures[] = 'min_wordlist_ratio';
        }

        if ($reversedRatio > $thresholds->maxReversedRatio) {
            $failures[] = 'max_reversed_ratio';
        }

        $reason = match (true) {
            in_array('min_letters', $failures, true) => ReviewReason::ImageOnly,
            in_array('max_reversed_ratio', $failures, true) => ReviewReason::SuspectedReversed,
            $failures !== [] => ReviewReason::LowQuality,
            default => null,
        };

        $score = (
            min(1.0, $letterRatio / max($thresholds->minLetterRatio, 0.0001))
            + min(1.0, $wordlistRatio / max($thresholds->minWordlistRatio, 0.0001))
            + (1.0 - min(1.0, $reversedRatio / max($thresholds->maxReversedRatio, 0.0001)))
        ) / 3;

        return new TextQualityReport(
            letters: $letters,
            nonWhitespace: $nonWhitespace,
            unmappedGlyphs: $unmapped,
            alphaTokens: $alphaTokens,
            letterRatio: $letterRatio,
            wordlistRatio: $wordlistRatio,
            reversedRatio: $reversedRatio,
            failures: $failures,
            reason: $reason,
            score: $score,
        );
    }
}
