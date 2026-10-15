<?php

namespace App\Domain\Text;

use Normalizer;

/**
 * Display/evidence normalization (TXT-P0-03): NFKC (repairs Arabic presentation
 * forms from PDFs), line-ending and whitespace cleanup, with an offset map to the
 * raw text. It never reorders or reverses text and never folds letters.
 */
final class TextNormalizer
{
    public function normalize(string $raw): NormalizedText
    {
        $codePoints = preg_split('//u', $raw, -1, PREG_SPLIT_NO_EMPTY);

        if ($codePoints === false) {
            return new NormalizedText('', [], 0);
        }

        /** @var list<string> $out */
        $out = [];
        /** @var list<int> $map raw index of each output code point */
        $map = [];
        $presentationForms = 0;
        $count = count($codePoints);
        $i = 0;

        while ($i < $count) {
            $start = $i;
            $segment = $codePoints[$i];
            $i++;

            // Keep a base character together with its combining marks so NFKC composes correctly.
            while ($i < $count && preg_match('/^\p{M}$/u', $codePoints[$i]) === 1) {
                $segment .= $codePoints[$i];
                $i++;
            }

            $segment = $this->cleanControlCharacters($segment);

            if ($segment === '') {
                continue;
            }

            if (preg_match('/[\x{FB50}-\x{FDFF}\x{FE70}-\x{FEFF}]/u', $segment) === 1) {
                $presentationForms++;
            }

            $normalized = Normalizer::normalize($segment, Normalizer::FORM_KC);

            foreach (preg_split('//u', $normalized === false ? $segment : $normalized, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $char) {
                $out[] = $char;
                $map[] = $start;
            }
        }

        [$out, $map] = $this->cleanWhitespace($out, $map);

        return new NormalizedText(implode('', $out), $this->compress($map), $presentationForms);
    }

    private function cleanControlCharacters(string $segment): string
    {
        $segment = str_replace(["\r\n", "\r"], "\n", $segment);

        // Keep \n, \t and \f (pdftotext page break); drop other C0/C1 controls, BOM and bidi overrides.
        return (string) preg_replace('/[\x{0000}-\x{0008}\x{000B}\x{000E}-\x{001F}\x{007F}-\x{009F}\x{FEFF}\x{202A}-\x{202E}]/u', '', $segment);
    }

    /**
     * Collapse horizontal whitespace runs, trim line ends, and keep at most one blank line.
     *
     * @param  list<string>  $chars
     * @param  list<int>  $map
     * @return array{0: list<string>, 1: list<int>}
     */
    private function cleanWhitespace(array $chars, array $map): array
    {
        $outChars = [];
        $outMap = [];

        foreach ($chars as $index => $char) {
            $isNewline = $char === "\n" || $char === "\f";
            $isSpace = ! $isNewline && preg_match('/^\s$/u', $char) === 1;

            if ($isSpace) {
                $char = ' ';
                $last = end($outChars);

                if ($last === false || $last === ' ' || $last === "\n" || $last === "\f") {
                    continue;
                }
            }

            if ($isNewline) {
                while (end($outChars) === ' ') {
                    array_pop($outChars);
                    array_pop($outMap);
                }

                $n = count($outChars);

                if ($char === "\n" && $n >= 2 && $outChars[$n - 1] === "\n" && $outChars[$n - 2] === "\n") {
                    continue;
                }
            }

            $outChars[] = $char;
            $outMap[] = $map[$index];
        }

        while ($outChars !== [] && in_array(end($outChars), [' ', "\n", "\f"], true)) {
            array_pop($outChars);
            array_pop($outMap);
        }

        return [$outChars, $outMap];
    }

    /**
     * @param  list<int>  $map
     * @return list<array{0: int, 1: int}>
     */
    private function compress(array $map): array
    {
        $runs = [];
        $previous = null;

        foreach ($map as $normalizedIndex => $rawIndex) {
            if ($previous === null || $rawIndex !== $previous + 1) {
                $runs[] = [$normalizedIndex, $rawIndex];
            }

            $previous = $rawIndex;
        }

        return $runs;
    }
}
