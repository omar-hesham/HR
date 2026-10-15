<?php

namespace App\Domain\Text;

/**
 * Suspicion check for visual (reversed) Arabic word order from some PDF generators
 * (stack-facts #12). A token counts as "reversed" when it is not a known word but
 * its characters reversed are. This is a heuristic: it flags, it never repairs.
 */
final class ReversedTextDetector
{
    public function __construct(private readonly Wordlist $wordlist) {}

    /**
     * $token must already be search-normalized.
     */
    public function isSuspected(string $token): bool
    {
        if (mb_strlen($token) < 3 || $this->wordlist->contains($token)) {
            return false;
        }

        return $this->wordlist->contains(self::reverse($token));
    }

    public static function reverse(string $token): string
    {
        $chars = preg_split('//u', $token, -1, PREG_SPLIT_NO_EMPTY);

        return implode('', array_reverse($chars === false ? [] : $chars));
    }
}
