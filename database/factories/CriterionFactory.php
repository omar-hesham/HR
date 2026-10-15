<?php

namespace Database\Factories;

use App\Domain\Criteria\CriterionField;
use App\Domain\Criteria\CriterionOperator;
use App\Domain\Criteria\CriterionType;
use App\Models\CriteriaVersion;
use App\Models\Criterion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Criterion>
 */
class CriterionFactory extends Factory
{
    /**
     * Default: a weighted nice-to-have skill criterion (spec §7.5 C4, illustrative).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'criteria_version_id' => CriteriaVersion::factory(),
            'type' => CriterionType::NiceToHave,
            'field' => CriterionField::Skills->value,
            'operator' => CriterionOperator::ContainsAny,
            'target_json' => ['values' => ['PLC']],
            'weight' => 40,
            'label_ar' => 'خبرة في PLC',
            'label_en' => 'PLC experience',
            'justification' => 'Production lines use PLC-controlled machines.',
            'legal_basis_note' => null,
            'sort' => 0,
        ];
    }

    public function mustHaveExperience(int $years = 2): static
    {
        return $this->state(fn (): array => [
            'type' => CriterionType::MustHave,
            'field' => CriterionField::ExperienceRelevantYears->value,
            'operator' => CriterionOperator::Gte,
            'target_json' => ['value' => $years],
            'weight' => null,
            'label_ar' => 'خبرة ذات صلة '.$years.' سنة على الأقل',
            'label_en' => 'At least '.$years.' years of relevant experience',
            'justification' => 'The role works unsupervised on live equipment.',
        ]);
    }
}
