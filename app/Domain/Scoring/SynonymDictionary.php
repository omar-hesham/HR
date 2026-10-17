<?php

namespace App\Domain\Scoring;

use App\Domain\Text\SearchNormalizer;

final readonly class SynonymDictionary
{
    /**
     * @param array<string, list<string>> $entries */
    public function __construct(private array $entries) {}

    public function canonical(string $value): string
    {
        $normalizer = new SearchNormalizer;
        $value = $normalizer->normalize($value);
        foreach ($this->entries as $canonical => $aliases) {
            foreach ([$canonical, ...$aliases] as $alias) {
                if ($normalizer->normalize($alias) === $value) {
                    return $normalizer->normalize($canonical);
                }
            }
        }

        return $value;
    }
}
