<?php

namespace App\Domain\Criteria;

use App\Domain\Text\SearchNormalizer;

/**
 * SC-P0-04 / kickoff §5.3–5.4: protected attributes and location are never criteria.
 * Field names are checked against a blocklist (on top of the CriterionField whitelist);
 * labels, justifications and targets are scanned for protected terms as whole tokens.
 */
final class ProtectedAttributeGuard
{
    /**
     * @param  list<string>  $protectedFields
     * @param  list<string>  $protectedTerms
     */
    public function __construct(
        private readonly array $protectedFields,
        private readonly array $protectedTerms,
    ) {}

    public static function fromConfig(): self
    {
        /** @var list<string> $fields */
        $fields = config('recruitment.protected.fields', []);
        /** @var list<string> $terms */
        $terms = config('recruitment.protected.terms', []);

        return new self($fields, $terms);
    }

    public function isProtectedField(string $field): bool
    {
        $normalized = strtolower(trim((string) preg_replace('/[^a-z0-9]+/i', '_', $field), '_'));
        $parts = explode('_', $normalized);

        foreach ($this->protectedFields as $protected) {
            if ($normalized === $protected) {
                return true;
            }

            if ($this->containsSequence($parts, explode('_', $protected))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Protected terms found in the given texts (empty list = clean).
     *
     * @param  list<string|null>  $texts
     * @return list<string>
     */
    public function findProtectedTerms(array $texts): array
    {
        $found = [];

        foreach ($texts as $text) {
            if ($text === null || trim($text) === '') {
                continue;
            }

            $tokens = $this->tokens($text);

            foreach ($this->protectedTerms as $term) {
                if ($this->containsSequence($tokens, $this->tokens($term))) {
                    $found[] = $term;
                }
            }
        }

        return array_values(array_unique($found));
    }

    /**
     * @return list<string>
     */
    private function tokens(string $text): array
    {
        preg_match_all('/[\p{L}\p{N}]+/u', SearchNormalizer::normalize($text), $matches);

        return $matches[0];
    }

    /**
     * @param  list<string>  $haystack
     * @param  list<string>  $needle
     */
    private function containsSequence(array $haystack, array $needle): bool
    {
        $n = count($needle);

        if ($n === 0) {
            return false;
        }

        for ($i = 0; $i + $n <= count($haystack); $i++) {
            if (array_slice($haystack, $i, $n) === $needle) {
                return true;
            }
        }

        return false;
    }
}
