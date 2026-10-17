<?php

namespace App\Domain\Ai;

use App\Domain\Text\SearchNormalizer;
use DateTimeImmutable;

/** Numeric statements are checked in PHP; overlapping employment is never double counted. */
final class FactSupportVerifier
{
    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed> */
    public function verify(array $payload): array
    {
        $payload['experience_total_years'] = $this->years($payload['experience_total_years'], $payload['previous_roles']);
        foreach ($payload['experience_relevant_years'] as $index => $experience) {
            $payload['experience_relevant_years'][$index]['years'] = $this->years($experience['years'], []);
        }

        return $payload;
    }

    /**
     * @param array<string,mixed> $fact
     * @param list<array<string,mixed>> $roles
     * @return array<string,mixed> */
    private function years(array $fact, array $roles): array
    {
        if ($fact['value'] === null || $fact['confidence'] === 0 || isset($fact['verification_error'])) {
            return $fact;
        }
        $units = implode('|', array_map(fn ($unit) => preg_quote($unit, '/'), config('ai-rules.year_units')));
        foreach ($fact['evidence'] as $evidence) {
            preg_match_all('/(?<![\p{N}.])(\d+(?:\.\d+)?)\h*(?:'.$units.')(?!\p{L})/iu', SearchNormalizer::westernDigits($evidence['quote']), $matches);
            foreach ($matches[1] as $number) {
                if (abs((float) $number - (float) $fact['value']) < .001) {
                    return $fact;
                }
            }
        }
        $union = $this->employmentUnion($roles);
        if ($union !== null && abs($union - (float) $fact['value']) <= .01) {
            return $fact;
        }
        $fact['confidence'] = 0;
        $fact['verification_error'] = 'value_not_supported';

        return $fact;
    }

    /**
     * @param list<array<string,mixed>> $roles */
    public function employmentUnion(array $roles): ?float
    {
        if ($roles === []) {
            return null;
        } $periods = [];
        foreach ($roles as $role) {
            $start = $this->date($role['start_date']);
            $end = $this->date($role['end_date']);
            if ($start === null || $end === null || $end <= $start) {
                return null;
            }
            $periods[] = [$start, $end];
        }
        usort($periods, fn ($a, $b) => $a[0] <=> $b[0]);
        $merged = [];
        foreach ($periods as [$start,$end]) {
            $last = count($merged) - 1;
            if ($last >= 0 && $start <= $merged[$last][1]) {
                $merged[$last][1] = max($merged[$last][1], $end);
            } else {
                $merged[] = [$start, $end];
            }
        }
        $days = 0;
        foreach ($merged as [$start,$end]) {
            $days += (int) $start->diff($end)->format('%a');
        }

        return round($days / 365.25, 2);
    }

    /**
     * @param array<string,mixed> $fact */
    private function date(array $fact): ?DateTimeImmutable
    {
        if (! is_string($fact['value']) || ($fact['confidence'] ?? 0) < .8 || empty($fact['evidence']) || isset($fact['verification_error'])) {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $fact['value']);

        return $date !== false && $date->format('Y-m-d') === $fact['value'] ? $date : null;
    }
}
