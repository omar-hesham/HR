<?php

namespace App\Domain\Scoring;

use App\Domain\Text\SearchNormalizer;

/** Pure, evaluation-only vocabulary mapping. Facts, quotes and confidence are never repaired. */
final readonly class RuleInputNormalizer
{
    /**
     * @param  array<string,mixed>  $vocabulary
     * @param  array<string,list<string>>  $synonyms
     * @param  list<string>  $levels
     */
    public function __construct(private array $vocabulary, private array $synonyms, private array $levels) {}

    /**
     * @param  array<string,mixed>  $fact
     * @param  list<string>  $targets
     * @return list<array<string,mixed>>
     */
    public function normalize(string $field, array $fact, array $targets = []): array
    {
        $raw = $fact['value'] ?? null;
        if (! is_string($raw)) {
            return [$fact];
        }
        if (in_array($field, ['shift_availability', 'can_reach_plant_for_shift', 'legal_minimum_age_confirmed'], true)) {
            if ($field !== 'shift_availability' && ! in_array($fact['source'] ?? 'model', ['human_answer', 'human_correction'], true)) {
                return [$fact];
            }
            $truth = $this->truth($raw, $field === 'shift_availability');
            $value = $truth === null ? $raw : ($field === 'shift_availability' ? ($truth ? 'available' : 'not_available') : $truth);

            return [$this->withValue($fact, $value)];
        }
        $entries = match ($field) {
            'education_level' => array_intersect_key($this->vocabulary['education_levels'] ?? [], array_flip($this->levels)),
            'education_field' => $this->vocabulary['education_fields'] ?? [],
            'skills', 'certifications', 'languages', 'previous_role_titles' => $this->synonyms,
            default => [], // Closed allowlist: no identity, protected attribute, address or location.
        };
        if ($field === 'education_level') {
            foreach ($this->levels as $level) {
                $entries[$level] ??= [];
            }
        }
        if ($entries === [] && ! in_array($field, ['education_field', 'skills', 'certifications', 'languages', 'previous_role_titles'], true)) {
            return [$fact];
        }
        $dictionary = new SynonymDictionary($this->synonyms);
        foreach ($entries as $canonical => $aliases) {
            $entries[$canonical] = [...$aliases, ...($this->synonyms[$canonical] ?? [])];
        }
        if ($field !== 'education_level' && $targets !== []) {
            $targetEntries = [];
            foreach ($targets as $target) {
                $aliases = [$target];
                foreach (array_replace($entries, $this->vocabulary[$field] ?? []) as $canonical => $terms) {
                    if ($dictionary->canonical($canonical) === $dictionary->canonical($target)) {
                        $aliases = [...$aliases, $canonical, ...$terms];
                    }
                }
                $key = $dictionary->canonical($target);
                $targetEntries[$key] = [...($targetEntries[$key] ?? []), ...$aliases];
            }
            $entries = $targetEntries;
        }
        $matches = $this->matches($raw, $entries);
        $values = array_values(array_unique(array_column($matches, 'canonical')));
        if ($values === [] || (in_array($field, ['education_level', 'education_field'], true) && count($values) !== 1)) {
            return [$fact];
        }

        if (in_array($field, ['education_level', 'education_field'], true) && $values !== ['none']) {
            foreach ([$raw, ...array_column($fact['evidence'] ?? [], 'quote')] as $statement) {
                if ($this->matches($statement, ['negative' => $this->vocabulary['negation'] ?? []]) !== []) {
                    return [$fact];
                }
            }
        }

        return array_map(fn (string $value): array => $this->withValue($fact, $value), $values);
    }

    public function truth(string $text, bool $shift = true): ?bool
    {
        if ($this->matches($text, ['uncertain' => $this->vocabulary['uncertainty'] ?? []]) !== []) {
            return null;
        }
        foreach ($this->vocabulary['boolean'] ?? [] as $canonical => $aliases) {
            foreach ($aliases as $alias) {
                if ($this->tokens($text) === $this->tokens($alias)) {
                    return $canonical === 'available';
                }
            }
        }
        $matches = $shift ? $this->matches($text, $this->vocabulary['shift'] ?? [], false) : [];
        if ($matches === []) {
            return null;
        }
        // Uncovered negation (including double negation) is ambiguous, never an affirmation.
        $negations = $this->matches($text, ['negative' => $this->vocabulary['negation'] ?? []]);
        foreach ($negations as $negation) {
            $covered = false;
            foreach ($matches as $match) {
                $covered = $covered || ($match['canonical'] === 'not_available' && $negation['start'] >= $match['start'] && $negation['end'] <= $match['end']);
            }
            if (! $covered) {
                return null;
            }
        }

        return ! in_array('not_available', array_column($matches, 'canonical'), true);
    }

    /**
     * @param  array<string,mixed>  $fact
     * @return array<string,mixed>
     */
    private function withValue(array $fact, string|bool $value): array
    {
        if ($fact['value'] !== $value) {
            $fact['normalized_from'] = $fact['normalized_from'] ?? $fact['value'];
            $fact['value'] = $value;
        }

        return $fact;
    }

    /**
     * @param  array<string,list<string>>  $entries
     * @return list<array{canonical:string,start:int,end:int}>
     */
    private function matches(string $text, array $entries, bool $includeCanonical = true): array
    {
        $text = $this->tokens($text);
        $matches = [];
        foreach ($entries as $canonical => $aliases) {
            foreach ($includeCanonical ? [$canonical, ...$aliases] : $aliases as $alias) {
                $alias = $this->tokens($alias);
                if ($alias === '') {
                    continue;
                }
                preg_match_all('/(?<![\p{L}\p{N}])'.preg_quote($alias, '/').'(?![\p{L}\p{N}])/u', $text, $found, PREG_OFFSET_CAPTURE);
                foreach ($found[0] as [$term, $start]) {
                    $matches[] = ['canonical' => $canonical, 'start' => $start, 'end' => $start + strlen($term)];
                }
            }
        }
        $specific = [];
        foreach ($matches as $match) {
            foreach ($matches as $other) {
                if ($other['canonical'] === $match['canonical']) {
                    continue;
                }
                if ($other['start'] === $match['start'] && $other['end'] === $match['end']) {
                    return []; // A shared alias with conflicting meanings is never guessed.
                }
                if ($other['start'] <= $match['start'] && $other['end'] >= $match['end']) {
                    continue 2; // "postgraduate diploma" takes precedence over "diploma".
                }
            }
            $specific[] = $match;
        }

        return $specific;
    }

    private function tokens(string $text): string
    {
        preg_match_all('/[\p{L}\p{N}]+/u', SearchNormalizer::normalize($text), $matches);

        return implode(' ', $matches[0]);
    }
}
