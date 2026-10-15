<?php

namespace App\Domain\Text;

use Normalizer;

/**
 * Search-only normalization (TXT-P0-03). The output is used for matching and the
 * candidates.search_normalized column; it must never be shown or quoted as evidence.
 */
final class SearchNormalizer
{
    /** Alef with hamza above/below, madda, wasla → bare alef (U+0627). */
    private const ALEF_VARIANTS = ["\u{0623}" => "\u{0627}", "\u{0625}" => "\u{0627}", "\u{0622}" => "\u{0627}", "\u{0671}" => "\u{0627}"];

    /** Alef maqsura → yeh, taa marbuta → heh. */
    private const LETTER_FOLDS = ["\u{0649}" => "\u{064A}", "\u{0629}" => "\u{0647}"];

    public static function normalize(string $text): string
    {
        $text = Normalizer::normalize($text, Normalizer::FORM_KC);

        if ($text === false) {
            return '';
        }

        $text = mb_strtolower($text, 'UTF-8');
        $text = strtr($text, self::ALEF_VARIANTS + self::LETTER_FOLDS);

        // Tashkeel (harakat, tanween, shadda, sukun, superscript alef) and tatweel.
        $text = (string) preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $text);

        // Zero-width and bidi control characters.
        $text = (string) preg_replace('/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]/u', '', $text);

        $text = self::westernDigits($text);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * Arabic-Indic (U+0660–0669) and Extended Arabic-Indic (U+06F0–06F9) digits to 0–9.
     */
    public static function westernDigits(string $text): string
    {
        $map = [];

        for ($i = 0; $i <= 9; $i++) {
            $map[mb_chr(0x0660 + $i, 'UTF-8')] = (string) $i;
            $map[mb_chr(0x06F0 + $i, 'UTF-8')] = (string) $i;
        }

        return strtr($text, $map);
    }
}
