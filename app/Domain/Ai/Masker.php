<?php

namespace App\Domain\Ai;

use App\Domain\Text\SearchNormalizer;

/** Deterministic masking is not anonymization; other people's names can remain. */
final class Masker
{
    /**
     * @return array{name: ?string, tokens: list<string>, ambiguous: bool} */
    public function identity(string $text, ?string $sender = null, ?string $filename = null): array
    {
        $names = [];
        $labels = implode('|', array_map(fn ($s) => preg_quote($s, '/'), config('ai-pipeline.identity.name_labels')));
        $lines = preg_split('/\R/u', $text) ?: [];
        foreach (array_slice($lines, 0, 8) as $line) {
            if (preg_match('/^\s*(?:'.$labels.')\s*:\s*(.+)$/iu', $line, $m)) {
                $names[] = trim($m[1]);
            }
        }
        if ($sender !== null && trim($sender) !== '') {
            $names[] = trim($sender, " \t\n\r\0\x0B\"");
        }
        if ($filename !== null) {
            $stem = preg_replace('/\.[^.]+$/u', '', basename($filename)) ?? '';
            $stem = preg_replace('/[_\-\d()]+/u', ' ', $stem) ?? '';
            $words = preg_split('/\s+/u', trim($stem)) ?: [];
            $words = array_values(array_filter($words, fn ($w) => ! $this->generic($w)));
            if (count($words) >= 2) {
                $names[] = implode(' ', $words);
            }
        }
        if ($names === []) {
            $first = trim($lines[0] ?? '');
            $words = preg_split('/\s+/u', $first) ?: [];
            if (count($words) >= 2 && count($words) <= 5 && ! array_filter($words, fn ($w) => $this->generic($w))) {
                $names[] = $first;
            }
        }
        $normalizer = new SearchNormalizer;
        $sets = [];
        foreach ($names as $name) {
            $tokens = preg_split('/\s+/u', $name) ?: [];
            if (count($tokens) < 2 || count($tokens) > 5 || preg_match('/^[\p{L}\p{M}\s\x{0027}\x{2019}]+$/u', $name) !== 1 || array_filter($tokens, fn ($w) => $this->generic($w))) {
                return ['name' => null, 'tokens' => [], 'ambiguous' => true];
            }
            $set = array_map($normalizer->normalize(...), $tokens);
            sort($set);
            $sets[] = $set;
        }
        if ($sets === []) {
            return ['name' => null, 'tokens' => [], 'ambiguous' => true];
        }
        $all = array_values(array_unique(array_merge(...$sets)));
        foreach ($sets as $set) {
            if ((array_diff($set, $sets[0]) !== [] && array_diff($sets[0], $set) !== [])) {
                return ['name' => null, 'tokens' => [], 'ambiguous' => true];
            }
        }

        return ['name' => $names[0], 'tokens' => $all, 'ambiguous' => false];
    }

    public function mask(string $text, DispatchContext $context = new DispatchContext, ?string $sender = null, ?string $filename = null): MaskedText
    {
        if (! mb_check_encoding($text, 'UTF-8')) {
            return new MaskedText('', $context);
        }
        $identity = $this->identity($text, $sender, $filename);
        if ($identity['ambiguous'] || preg_match('/\[PII_\d+\]/', $text)) {
            return new MaskedText('', $context);
        }
        $digits = strtr($text, array_combine(preg_split('//u', "\u{0660}\u{0661}\u{0662}\u{0663}\u{0664}\u{0665}\u{0666}\u{0667}\u{0668}\u{0669}\u{06F0}\u{06F1}\u{06F2}\u{06F3}\u{06F4}\u{06F5}\u{06F6}\u{06F7}\u{06F8}\u{06F9}", -1, PREG_SPLIT_NO_EMPTY), str_split('01234567890123456789')));
        $spans = [];
        foreach (config('ai-pipeline.mask_patterns') as $pattern) {
            if (preg_match_all($pattern, $digits, $matches, PREG_OFFSET_CAPTURE) === false) {
                return new MaskedText('', $context);
            }
            foreach ($matches[0] as [$match, $byte]) {
                $spans[] = [mb_strlen(substr($digits, 0, $byte), 'UTF-8'), mb_strlen($match, 'UTF-8')];
            }
        }
        preg_match_all('/[\p{L}\p{M}]+/u', $text, $words, PREG_OFFSET_CAPTURE);
        foreach ($words[0] as [$word, $byte]) {
            if (in_array((new SearchNormalizer)->normalize($word), $identity['tokens'], true)) {
                $spans[] = [mb_strlen(substr($text, 0, $byte), 'UTF-8'), mb_strlen($word, 'UTF-8')];
            }
        }
        usort($spans, fn ($a, $b) => $a[0] <=> $b[0] ?: $b[1] <=> $a[1]);
        $map = [];
        $cursor = 0;
        $out = '';
        foreach ($spans as [$start, $length]) {
            if ($start < $cursor) {
                continue;
            }
            $placeholder = '[PII_'.str_pad((string) (count($map) + 1), 5, '0', STR_PAD_LEFT).']';
            $out .= mb_substr($text, $cursor, $start - $cursor, 'UTF-8').$placeholder;
            $map[$placeholder] = mb_substr($text, $start, $length, 'UTF-8');
            $cursor = $start + $length;
        }
        $out .= mb_substr($text, $cursor, null, 'UTF-8');

        return new MaskedText($out, $context, $map, true);
    }

    private function generic(string $word): bool
    {
        return in_array((new SearchNormalizer)->normalize($word), config('ai-pipeline.identity.generic_words'), true);
    }
}
