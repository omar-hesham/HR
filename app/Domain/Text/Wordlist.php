<?php

namespace App\Domain\Text;

/**
 * Arabic + English vocabulary used by the text-quality check (M2-P0-04).
 * Entries and lookups are compared in search-normalized form.
 */
final class Wordlist
{
    /**
     * Arabic proclitics, longest first: wa-bi-al, wa-al, bi-al, ka-al, fa-al, li-l, al, wa, bi, li, fa, ka.
     */
    private const PREFIXES = [
        "\u{0648}\u{0628}\u{0627}\u{0644}", "\u{0648}\u{0627}\u{0644}", "\u{0628}\u{0627}\u{0644}", "\u{0643}\u{0627}\u{0644}",
        "\u{0641}\u{0627}\u{0644}", "\u{0644}\u{0644}", "\u{0627}\u{0644}", "\u{0648}", "\u{0628}", "\u{0644}", "\u{0641}", "\u{0643}",
    ];

    /**
     * Common Arabic suffixes, already search-normalized (taa marbuta → heh): -yat, -at, -in, -un, -iyya, -a, -i.
     */
    private const SUFFIXES = [
        "\u{064A}\u{0627}\u{062A}", "\u{0627}\u{062A}", "\u{064A}\u{0646}", "\u{0648}\u{0646}", "\u{064A}\u{0647}", "\u{0647}", "\u{064A}",
    ];

    /** @var array<string, true> */
    private array $words = [];

    /**
     * @param  iterable<string>  $words
     */
    public function __construct(iterable $words)
    {
        foreach ($words as $word) {
            $normalized = SearchNormalizer::normalize($word);

            if ($normalized !== '' && ! str_starts_with($normalized, '#')) {
                $this->words[$normalized] = true;
            }
        }
    }

    /**
     * @param  list<string>  $paths
     */
    public static function fromFiles(array $paths): self
    {
        $words = [];

        foreach ($paths as $path) {
            $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

            foreach ($lines === false ? [] : $lines as $line) {
                $words[] = trim($line);
            }
        }

        return new self($words);
    }

    public static function default(): self
    {
        static $instance = null;

        return $instance ??= self::fromFiles([
            resource_path('wordlists/ar.txt'),
            resource_path('wordlists/en.txt'),
        ]);
    }

    public function count(): int
    {
        return count($this->words);
    }

    /**
     * $token must already be search-normalized.
     */
    public function contains(string $token): bool
    {
        if (isset($this->words[$token])) {
            return true;
        }

        if (preg_match('/\p{Arabic}/u', $token) !== 1) {
            return false;
        }

        foreach ($this->candidateStems($token) as $stem) {
            if (isset($this->words[$stem])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function candidateStems(string $token): array
    {
        $stems = [];
        $bases = [$token];

        foreach (self::PREFIXES as $prefix) {
            if (str_starts_with($token, $prefix) && mb_strlen($token) - mb_strlen($prefix) >= 2) {
                $bases[] = mb_substr($token, mb_strlen($prefix));
            }
        }

        foreach ($bases as $base) {
            $stems[] = $base;

            foreach (self::SUFFIXES as $suffix) {
                if (str_ends_with($base, $suffix) && mb_strlen($base) - mb_strlen($suffix) >= 2) {
                    $stems[] = mb_substr($base, 0, mb_strlen($base) - mb_strlen($suffix));
                }
            }
        }

        return $stems;
    }
}
