<?php

namespace App\Domain\Criteria;

/**
 * M4-P0-01 / M4-P0-03 / SC-P0-02 / SC-P0-04 validation, independent of the UI.
 * validateCriterion() runs on every save; validateVersion() runs before approval.
 */
final class CriteriaValidator
{
    /**
     * @param  list<string>  $qualificationLevels
     * @param  list<string>  $ageTerms  protected terms a legal-minimum-age criterion may mention
     */
    public function __construct(
        private readonly ProtectedAttributeGuard $guard,
        private readonly array $qualificationLevels,
        private readonly array $ageTerms = [],
    ) {}

    public static function fromConfig(): self
    {
        /** @var list<string> $levels */
        $levels = config('recruitment.qualification_levels', []);
        /** @var list<string> $ageTerms */
        $ageTerms = config('recruitment.protected.age_terms', []);

        return new self(ProtectedAttributeGuard::fromConfig(), $levels, $ageTerms);
    }

    public function validateCriterion(CriterionData $data): CriteriaValidationResult
    {
        $result = new CriteriaValidationResult;

        $type = CriterionType::tryFrom($data->type);
        $operator = CriterionOperator::tryFrom($data->operator);
        $field = CriterionField::tryFrom($data->field);

        if ($type === null) {
            $result->add('type', 'app.criteria.errors.type_invalid');
        }

        // The whitelist decides; the blocklist only gives a clearer message for rejected names.
        if ($field === null) {
            $result->add(
                'field',
                $this->guard->isProtectedField($data->field) ? 'app.criteria.errors.field_protected' : 'app.criteria.errors.field_unknown',
                ['field' => $data->field],
            );
        }

        if ($operator === null) {
            $result->add('operator', 'app.criteria.errors.operator_invalid');
        } elseif ($field !== null && ! in_array($operator, $field->operators(), true)) {
            $result->add('operator', 'app.criteria.errors.operator_not_allowed', ['operator' => $operator->value]);
        }

        if ($type !== null) {
            $this->validateWeight($type, $data->weight, $result);
        }

        if ($data->labelAr === '') {
            $result->add('label_ar', 'app.criteria.errors.label_ar_required');
        }

        if ($data->labelEn === '') {
            $result->add('label_en', 'app.criteria.errors.label_en_required');
        }

        if ($data->justification === '') {
            $result->add('justification', 'app.criteria.errors.justification_required');
        }

        $needsLegalBasis = ($field?->requiresLegalBasis() ?? false) || $type === CriterionType::Disqualifier;

        if ($needsLegalBasis && trim((string) $data->legalBasisNote) === '') {
            $result->add('legal_basis_note', 'app.criteria.errors.legal_basis_required');
        }

        if ($field !== null && $operator !== null && in_array($operator, $field->operators(), true)) {
            $this->validateTarget($field, $operator, $data->target, $result);
        }

        $terms = $this->guard->findProtectedTerms([
            $data->labelAr,
            $data->labelEn,
            $data->justification,
            ...$this->targetStrings($data->target),
        ]);

        if ($field === CriterionField::LegalMinimumAgeConfirmed) {
            $terms = array_values(array_diff($terms, $this->ageTerms));
        }

        if ($terms !== []) {
            $result->add('label_ar', 'app.criteria.errors.protected_terms', ['terms' => implode(', ', $terms)]);
        }

        return $result;
    }

    /**
     * @param  list<CriterionData>  $criteria
     */
    public function validateVersion(array $criteria): CriteriaValidationResult
    {
        $result = new CriteriaValidationResult;

        if ($criteria === []) {
            $result->add('version', 'app.criteria.errors.version_empty');

            return $result;
        }

        foreach ($criteria as $index => $criterion) {
            if (! $this->validateCriterion($criterion)->passes()) {
                $result->add('version', 'app.criteria.errors.version_has_invalid', ['number' => $index + 1]);
            }
        }

        $weighted = array_filter($criteria, static fn (CriterionData $c): bool => $c->type === CriterionType::NiceToHave->value && ($c->weight ?? 0) > 0);

        if ($weighted === []) {
            $result->add('version', 'app.criteria.errors.version_needs_weighted');
        }

        $this->detectConflicts($criteria, $result);

        return $result;
    }

    private function validateWeight(CriterionType $type, ?int $weight, CriteriaValidationResult $result): void
    {
        if ($type->isWeighted()) {
            if ($weight === null || $weight < 1 || $weight > 100) {
                $result->add('weight', 'app.criteria.errors.weight_range');
            }

            return;
        }

        if ($weight !== null) {
            $result->add('weight', 'app.criteria.errors.weight_not_allowed');
        }
    }

    /**
     * @param  array<string, mixed>  $target
     */
    private function validateTarget(CriterionField $field, CriterionOperator $operator, array $target, CriteriaValidationResult $result): void
    {
        switch ($operator) {
            case CriterionOperator::IsExplicitlyTrue:
                return;

            case CriterionOperator::Gte:
            case CriterionOperator::Lte:
            case CriterionOperator::Equals:
                $value = $target['value'] ?? null;

                if ($field->valueType() === 'number') {
                    if (! is_numeric($value) || (float) $value < 0) {
                        $result->add('target', 'app.criteria.errors.target_number');
                    }

                    return;
                }

                $this->validateEnumValues($field, [$value], $result);

                return;

            case CriterionOperator::Between:
                $min = $target['min'] ?? null;
                $max = $target['max'] ?? null;

                if (! is_numeric($min) || ! is_numeric($max) || (float) $min < 0 || (float) $min > (float) $max) {
                    $result->add('target', 'app.criteria.errors.target_between');
                }

                return;

            case CriterionOperator::In:
            case CriterionOperator::ContainsAny:
            case CriterionOperator::ContainsAll:
                $values = $target['values'] ?? null;

                if (! is_array($values) || $values === []) {
                    $result->add('target', 'app.criteria.errors.target_list');

                    return;
                }

                foreach ($values as $value) {
                    if (! is_scalar($value) || trim((string) $value) === '') {
                        $result->add('target', 'app.criteria.errors.target_list');

                        return;
                    }
                }

                $this->validateEnumValues($field, array_values($values), $result);
        }
    }

    /**
     * @param  list<mixed>  $values
     */
    private function validateEnumValues(CriterionField $field, array $values, CriteriaValidationResult $result): void
    {
        $allowed = match ($field->valueType()) {
            'level' => $this->qualificationLevels,
            'military' => CriterionField::MILITARY_VALUES,
            default => null,
        };

        if ($allowed === null) {
            foreach ($values as $value) {
                if (! is_scalar($value) || trim((string) $value) === '') {
                    $result->add('target', 'app.criteria.errors.target_value');

                    return;
                }
            }

            return;
        }

        foreach ($values as $value) {
            if (! is_string($value) || ! in_array($value, $allowed, true)) {
                $result->add('target', 'app.criteria.errors.target_not_in_list');

                return;
            }
        }
    }

    /**
     * @param  list<CriterionData>  $criteria
     */
    private function detectConflicts(array $criteria, CriteriaValidationResult $result): void
    {
        $seen = [];
        $ranges = [];

        foreach ($criteria as $criterion) {
            $signature = $criterion->field.'|'.$criterion->operator.'|'.json_encode($this->canonical($criterion->target));
            $seen[$signature][] = $criterion->type;

            if ($criterion->type !== CriterionType::MustHave->value) {
                continue;
            }

            $bounds = $this->bounds($criterion);

            if ($bounds !== null) {
                $ranges[$criterion->field][] = $bounds;
            }
        }

        foreach ($seen as $signature => $types) {
            $field = explode('|', $signature)[0];

            if (count($types) > 1 && count(array_unique($types)) === count($types)) {
                if (in_array(CriterionType::MustHave->value, $types, true) && in_array(CriterionType::Disqualifier->value, $types, true)) {
                    $result->add('version', 'app.criteria.errors.conflict_must_and_disqualifier', ['field' => $field]);
                }
            } elseif (count($types) > 1) {
                $result->add('version', 'app.criteria.errors.conflict_duplicate', ['field' => $field]);
            }
        }

        foreach ($ranges as $field => $fieldRanges) {
            $low = max(array_column($fieldRanges, 0));
            $high = min(array_column($fieldRanges, 1));

            if ($low > $high) {
                $result->add('version', 'app.criteria.errors.conflict_range', ['field' => $field]);
            }
        }
    }

    /**
     * @return array{0: float, 1: float}|null
     */
    private function bounds(CriterionData $criterion): ?array
    {
        $field = CriterionField::tryFrom($criterion->field);

        if ($field === null || $field->valueType() !== 'number') {
            return null;
        }

        $target = $criterion->target;

        return match ($criterion->operator) {
            CriterionOperator::Gte->value => is_numeric($target['value'] ?? null) ? [(float) $target['value'], INF] : null,
            CriterionOperator::Lte->value => is_numeric($target['value'] ?? null) ? [-INF, (float) $target['value']] : null,
            CriterionOperator::Between->value => is_numeric($target['min'] ?? null) && is_numeric($target['max'] ?? null)
                ? [(float) $target['min'], (float) $target['max']]
                : null,
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $target
     * @return array<string, mixed>
     */
    private function canonical(array $target): array
    {
        ksort($target);

        if (isset($target['values']) && is_array($target['values'])) {
            $values = array_map(static fn (mixed $v): string => is_scalar($v) ? mb_strtolower(trim((string) $v)) : '', $target['values']);
            sort($values);
            $target['values'] = $values;
        }

        return $target;
    }

    /**
     * @param  array<string, mixed>  $target
     * @return list<string>
     */
    private function targetStrings(array $target): array
    {
        $strings = [];

        array_walk_recursive($target, static function (mixed $value) use (&$strings): void {
            if (is_string($value)) {
                $strings[] = $value;
            }
        });

        return $strings;
    }
}
