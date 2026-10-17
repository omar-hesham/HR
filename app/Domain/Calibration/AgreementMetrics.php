<?php

namespace App\Domain\Calibration;

final class AgreementMetrics
{
    /** @param list<array{application_id:int,manager_rank:float,tool_rank:?float,suitable:bool,presented:bool}> $entries
     * @return array<string,mixed>
     */
    public function calculate(array $entries): array
    {
        $manager = $entries;
        $tool = array_values(array_filter($entries, fn (array $e): bool => $e['tool_rank'] !== null));
        usort($manager, fn (array $a, array $b): int => $a['manager_rank'] <=> $b['manager_rank'] ?: $a['application_id'] <=> $b['application_id']);
        usort($tool, fn (array $a, array $b): int => $a['tool_rank'] <=> $b['tool_rank'] ?: $a['application_id'] <=> $b['application_id']);
        $overlap = count(array_intersect(array_column(array_slice($manager, 0, 5), 'application_id'), array_column(array_slice($tool, 0, 5), 'application_id')));
        $paired = array_values(array_filter($entries, fn (array $e): bool => $e['tool_rank'] !== null));
        $x = $this->averageRanks(array_column($paired, 'manager_rank'));
        $y = $this->averageRanks(array_column($paired, 'tool_rank'));
        $spearman = $this->correlation($x, $y);
        $missed = count(array_filter($entries, fn (array $e): bool => $e['suitable'] && ! $e['presented']));
        $ties = count($x) - count(array_unique($x)) + count($y) - count(array_unique($y));

        return ['sample_size' => count($entries), 'paired_size' => count($paired), 'top5_overlap' => $overlap, 'top5_denominator' => min(5, count($manager)), 'spearman' => $spearman, 'suitable_not_presented' => $missed, 'ties' => $ties,
            'targets_met' => count($entries) >= 5 && count($paired) === count($entries) && $overlap >= 4 && $spearman !== null && $spearman >= .70 && $missed === 0,
            'tie_method' => 'average_ranks; top5 boundary ties use opaque application id', 'limitations' => count($entries) < 20 ? 'small_synthetic_or_pilot_sample; no_population_claim' : 'per_job_sample_only; manager_approval_required'];
    }

    /**
     * @param  list<float>  $values
     * @return list<float>
     */
    private function averageRanks(array $values): array
    {
        $ranks = [];
        foreach ($values as $value) {
            $less = count(array_filter($values, fn (float $other): bool => $other < $value));
            $equal = count(array_filter($values, fn (float $other): bool => $other === $value));
            $ranks[] = $less + ($equal + 1) / 2;
        }

        return $ranks;
    }

    /**
     * @param  list<float>  $x
     * @param  list<float>  $y
     */
    private function correlation(array $x, array $y): ?float
    {
        if (count($x) < 2) {
            return null;
        }
        $meanX = array_sum($x) / count($x);
        $meanY = array_sum($y) / count($y);
        $numerator = 0.;
        $denomX = 0.;
        $denomY = 0.;
        foreach ($x as $i => $rank) {
            $dx = $rank - $meanX;
            $dy = $y[$i] - $meanY;
            $numerator += $dx * $dy;
            $denomX += $dx ** 2;
            $denomY += $dy ** 2;
        }

        return $denomX === 0. || $denomY === 0. ? null : $numerator / sqrt($denomX * $denomY);
    }
}
