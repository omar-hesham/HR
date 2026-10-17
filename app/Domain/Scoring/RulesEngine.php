<?php

namespace App\Domain\Scoring;

use App\Domain\Criteria\CriterionData;
use App\Domain\Criteria\CriterionField;
use App\Domain\Criteria\CriterionOperator;

/** Pure evaluation: no database, AI, clock, application status, or score mutation. */
final class RulesEngine
{
    public const VERSION = '1.0';

    /**
     * @param list<CriterionData> $criteria
     * @param array<string,mixed> $facts
     * @param array<string,mixed> $snapshot
     * @return array<string,mixed> */
    public function evaluate(array $criteria, array $facts, string $jobId, bool $approved, array $snapshot): array
    {
        $results = [];
        $total = 0;
        $met = 0;
        $covered = 0;
        $weak = false;
        $review = false;
        $dictionary = new SynonymDictionary($snapshot['synonyms'] ?? []);
        foreach ($criteria as $criterion) {
            $field = CriterionField::tryFrom($criterion->field);
            if ($field === null || ! in_array(CriterionOperator::tryFrom($criterion->operator), $field->operators(), true)) {
                $out = $this->result('unclear');
            } else {
                $out = $this->evaluateCriterion($criterion, $this->project($facts, $field, $jobId), $snapshot, $dictionary);
            }
            $results[] = $out;
            if ($criterion->type === 'nice_to_have' && ($criterion->weight ?? 0) > 0) {
                $weight = $criterion->weight;
                $total += $weight;
                if ($out['result'] === 'met') {
                    $met += $weight;
                }
                if (in_array($out['result'], ['met', 'not_met'], true)) {
                    $covered += $weight;
                }
            }
            if ($criterion->type === 'must_have') {
                $weak = $weak || $out['result'] === 'not_met';
                $review = $review || in_array($out['result'], ['not_mentioned', 'unclear'], true);
            }
            if ($criterion->type === 'disqualifier') {
                $weak = $weak || $out['result'] === 'met';
                $review = $review || $out['result'] !== 'not_met';
            }
        }
        if (! $approved || $total <= 0 || ! isset($snapshot['strong'], $snapshot['review'], $snapshot['confidence'], $snapshot['education_levels'], $snapshot['synonyms'])) {
            return ['score' => null, 'coverage' => null, 'band' => 'configuration_review', 'review' => true, 'results' => $results];
        }
        $score = round(100 * $met / $total, 2);
        $coverage = round(100 * $covered / $total, 2);
        $band = $weak ? 'weak_match' : ($review ? 'needs_review' : ($score >= $snapshot['strong'] ? 'strong_match' : ($score >= $snapshot['review'] ? 'needs_review' : 'weak_match')));

        return ['score' => $score, 'coverage' => $coverage, 'band' => $band, 'review' => $review || $weak || $band !== 'strong_match', 'results' => $results];
    }

    /**
     * @param array<string,mixed> $facts
     * @return list<array<string,mixed>> */
    private function project(array $facts, CriterionField $field, string $jobId): array
    {
        return match ($field) {
            CriterionField::EducationLevel => array_map(fn ($education) => $education['level'] + ['education_field' => $education['field']], $facts['education'] ?? []),
            CriterionField::EducationField => array_column($facts['education'] ?? [], 'field'),
            CriterionField::ExperienceRelevantYears => array_values(array_map(fn ($f) => $f['years'], array_filter($facts['experience_relevant_years'] ?? [], fn ($f) => $f['job_id'] === $jobId))),
            CriterionField::PreviousRoleTitles => array_column($facts['previous_roles'] ?? [], 'title'),
            CriterionField::Skills, CriterionField::Certifications, CriterionField::Languages => $facts[$field->value] ?? [],
            CriterionField::ExpectedSalary => isset($facts['expected_salary']['amount']) ? [$facts['expected_salary']['amount'] + ['currency' => $facts['expected_salary']['currency'], 'period' => $facts['expected_salary']['period']]] : [],
            default => isset($facts[$field->value]) ? [$facts[$field->value]] : [],
        };
    }

    /**
     * @param list<array<string,mixed>> $facts
     * @param array<string,mixed> $snapshot
     * @return array<string,mixed> */
    private function evaluateCriterion(CriterionData $criterion, array $facts, array $snapshot, SynonymDictionary $dictionary): array
    {
        if (in_array($criterion->field, ['can_reach_plant_for_shift', 'legal_minimum_age_confirmed'], true)) {
            $facts = array_values(array_filter($facts, fn ($f) => in_array($f['source'] ?? 'model', ['human_answer', 'human_correction'], true)));
        }
        if ($criterion->field === 'military_status' && empty($snapshot['military_enabled'])) {
            return $this->result('not_mentioned');
        }
        $values = [];
        $unreliable = [];
        $evidence = [];
        $confidence = 1.0;
        $source = 'model';
        foreach ($facts as $fact) {
            if (($fact['value'] ?? null) === null && empty($fact['verification_error'])) {
                continue;
            }
            if (isset($fact['verification_error']) || ($fact['confidence'] ?? 0) < max(.80, ($snapshot['confidence'] ?? .80)) || empty($fact['evidence'])) {
                $unreliable[] = $fact;

                continue;
            }
            foreach ($fact['evidence'] as $item) {
                if (! isset($item['quote']) || $item['quote'] === '') {
                    $unreliable[] = $fact;

                    continue 2;
                }
                if (($fact['source'] ?? 'model') === 'model' && (! isset($item['document_id'],$item['start'],$item['end']) || $item['end'] <= $item['start'])) {
                    $unreliable[] = $fact;

                    continue 2;
                }
            }
            $values[] = $fact;
            $confidence = min($confidence, (float) $fact['confidence']);
            $evidence = [...$evidence, ...$fact['evidence']];
            if (($fact['source'] ?? 'model') !== 'model') {
                $source = $fact['source'];
            }
        }
        if ($values === []) {
            return $this->result($unreliable === [] ? 'not_mentioned' : 'unclear', [], $unreliable === [] ? 0 : (float) min(array_column($unreliable, 'confidence')));
        }
        $operator = $criterion->operator;
        $target = $criterion->target;
        if (in_array($operator, ['contains_any', 'contains_all'], true)) {
            $outcomes = [];
            $matchedFacts = [];
            foreach ($target['values'] ?? [] as $needle) {
                $matched = array_values(array_filter($values, fn ($f) => $dictionary->canonical((string) $f['value']) === $dictionary->canonical((string) $needle)));
                if ($matched === []) {
                    $outcomes[] = $unreliable === [] ? 'not_mentioned' : 'unclear';

                    continue;
                }
                $matchedFacts = [...$matchedFacts, ...$matched];
                $negative = array_map(fn ($f) => $this->negative($f, $snapshot), $matched);
                $outcomes[] = count(array_unique($negative)) > 1 ? 'unclear' : ($negative[0] ? 'not_met' : 'met');
            }
            if ($operator === 'contains_any') {
                $outcome = in_array('met', $outcomes, true) ? 'met' : (in_array('unclear', $outcomes, true) ? 'unclear' : (in_array('not_mentioned', $outcomes, true) ? 'not_mentioned' : 'not_met'));
            } else {
                $outcome = in_array('not_met', $outcomes, true) ? 'not_met' : (in_array('unclear', $outcomes, true) ? 'unclear' : (in_array('not_mentioned', $outcomes, true) ? 'not_mentioned' : 'met'));
            }
            $matchedEvidence = [];
            $matchedConfidence = $matchedFacts === [] ? 0 : 1;
            $matchedSource = 'model';
            foreach ($matchedFacts as $matchedFact) {
                $matchedEvidence = [...$matchedEvidence, ...$matchedFact['evidence']];
                $matchedConfidence = min($matchedConfidence, (float) $matchedFact['confidence']);
                if (($matchedFact['source'] ?? 'model') !== 'model') {
                    $matchedSource = $matchedFact['source'];
                }
            }

            return $this->result($outcome, $matchedEvidence, $matchedConfidence, $matchedSource);
        }
        if ($criterion->field === 'education_level') {
            $assessment = $this->education($criterion, $values, $snapshot, $dictionary);
            if ($unreliable !== [] && $assessment['result'] !== 'met') {
                $assessment['result'] = 'unclear';
            }

            return $assessment;
        }
        $comparisons = [];
        foreach ($values as $fact) {
            $value = $fact['value'];
            $desired = $target['value'] ?? null;
            if ($criterion->field === 'expected_salary') {
                if (! isset($snapshot['salary_currency'],$snapshot['salary_period']) || ($fact['currency']['value'] ?? null) !== $snapshot['salary_currency'] || ($fact['period']['value'] ?? null) !== $snapshot['salary_period'] || ($fact['currency']['confidence'] ?? 0) < ($snapshot['confidence'] ?? .8) || ($fact['period']['confidence'] ?? 0) < ($snapshot['confidence'] ?? .8)) {
                    return $this->result('unclear', $evidence, 0, $source);
                }
            }
            if ($operator === 'is_explicitly_true') {
                $truth = $this->explicitTruth($fact, $snapshot);
                if ($truth === null) {
                    return $this->result('unclear', $evidence, $confidence, $source);
                }
                // A negative disqualifier proposition must be explicitly configured and approved.
                $comparisons[] = $truth === ($target['value'] ?? true);

                continue;
            }
            if (in_array($operator, ['gte', 'lte', 'between'], true) && ! is_numeric($value)) {
                return $this->result('unclear', $evidence, 0, $source);
            }
            $comparisons[] = match ($operator) {
                'equals' => $dictionary->canonical((string) $value) === $dictionary->canonical((string) $desired),
                'in' => in_array($criterion->field === 'education_level' ? ($snapshot['education_levels'][$value] ?? '') : $dictionary->canonical((string) $value), array_map(fn ($v) => $dictionary->canonical((string) $v), $target['values'] ?? []), true),
                'gte' => (float) $value >= (float) $desired, 'lte' => (float) $value <= (float) $desired,
                'between' => (float) $value >= (float) ($target['min'] ?? 0) && (float) $value <= (float) ($target['max'] ?? 0),
                default => false,
            };
        }
        // Education can have several legitimate qualifications; choose a confirmed match.
        if ($criterion->field === 'education_level' || $criterion->field === 'education_field') {
            $comparisons = [in_array(true, $comparisons, true)];
        }
        $outcome = count(array_unique($comparisons, SORT_REGULAR)) > 1 ? 'unclear' : ($comparisons[0] ? 'met' : 'not_met');
        if ($unreliable !== [] && $outcome === 'not_met') {
            $outcome = 'unclear';
        }

        return $this->result($outcome, $evidence, $confidence, $source);
    }

    /**
     * @param  list<array<string,mixed>>  $qualifications
     * @param  array<string,mixed>  $snapshot
     * @return array<string,mixed>
     */
    private function education(CriterionData $criterion, array $qualifications, array $snapshot, SynonymDictionary $dictionary): array
    {
        $levels = array_map($dictionary->canonical(...), $snapshot['education_levels'] ?? []);
        $assessments = [];
        foreach ($qualifications as $qualification) {
            $rank = array_search($dictionary->canonical((string) $qualification['value']), $levels, true);
            $desired = array_search($dictionary->canonical((string) ($criterion->target['value'] ?? '')), $levels, true);
            if ($rank === false || ($criterion->operator !== 'in' && $desired === false)) {
                $assessments[] = $this->result('unclear');

                continue;
            }
            $evidence = $qualification['evidence'];
            $confidence = (float) $qualification['confidence'];
            $source = $qualification['source'] ?? 'model';
            if (isset($criterion->target['field'])) {
                $field = $qualification['education_field'];
                if (($field['value'] ?? null) === null) {
                    $assessments[] = $this->result('not_mentioned');

                    continue;
                }
                if (($field['confidence'] ?? 0) < max(.8, $snapshot['confidence']) || empty($field['evidence']) || isset($field['verification_error'])) {
                    $assessments[] = $this->result('unclear');

                    continue;
                }
                $evidence = [...$evidence, ...$field['evidence']];
                $confidence = min($confidence, (float) $field['confidence']);
                if ($dictionary->canonical((string) $field['value']) !== $dictionary->canonical((string) $criterion->target['field'])) {
                    $assessments[] = $this->result('not_met', $evidence, $confidence, $source);

                    continue;
                }
            }
            $met = match ($criterion->operator) {
                'gte' => $rank >= $desired,'equals' => $rank === $desired,
                'in' => in_array($levels[$rank], array_map($dictionary->canonical(...), $criterion->target['values'] ?? []), true),
                default => false,
            };
            $assessments[] = $this->result($met ? 'met' : 'not_met', $evidence, $confidence, $source);
        }
        foreach (['met', 'unclear', 'not_mentioned', 'not_met'] as $outcome) {
            foreach ($assessments as $assessment) {
                if ($assessment['result'] === $outcome) {
                    return $assessment;
                }
            }
        }

        return $this->result('not_mentioned');
    }

    /**
     * @param array<string,mixed> $fact
     * @param array<string,mixed> $snapshot */
    private function negative(array $fact, array $snapshot): bool
    {
        foreach ($fact['evidence'] as $item) {
            foreach ($snapshot['negative_markers'] ?? [] as $marker) {
                if (preg_match('/(?<![\p{L}\p{N}])'.preg_quote($marker, '/').'(?![\p{L}\p{N}])/iu', $item['quote'])) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param array<string,mixed> $fact
     * @param array<string,mixed> $snapshot */
    private function explicitTruth(array $fact, array $snapshot): ?bool
    {
        $dictionary = new SynonymDictionary($snapshot['synonyms'] ?? []);
        $value = $dictionary->canonical((string) $fact['value']);
        if (in_array($fact['source'] ?? 'model', ['human_answer', 'human_correction'], true) && is_bool($fact['value'])) {
            return $fact['value'];
        }
        foreach ($fact['evidence'] as $item) {
            foreach ($snapshot['negative_shift_statements'] ?? [] as $statement) {
                if (mb_stripos($item['quote'], $statement, 0, 'UTF-8') !== false) {
                    return false;
                }
            }
        }
        if (in_array($value, ['true', 'yes', 'available'], true)) {
            foreach ($fact['evidence'] as $item) {
                foreach ($snapshot['affirmative_statements'] ?? [] as $statement) {
                    if (mb_stripos($item['quote'], $statement, 0, 'UTF-8') !== false) {
                        return true;
                    }
                }
            }
        }

        return null;
    }

    /**
     * @param list<array<string,mixed>> $evidence
     * @return array<string,mixed> */
    private function result(string $result, array $evidence = [], float $confidence = 0, string $source = 'model'): array
    {
        return compact('result', 'evidence', 'confidence', 'source');
    }
}
