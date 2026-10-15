<?php

/**
 * T-M4-02 / T-SC-03 (validation part): weights, operators, targets, conflicts,
 * protected attributes (kickoff §5.4) and location (kickoff §5.3).
 */

use App\Domain\Criteria\CriteriaValidator;
use App\Domain\Criteria\CriterionData;

/**
 * @param  array<string, mixed>  $overrides
 */
function makeCriterion(array $overrides = []): CriterionData
{
    return CriterionData::fromArray(array_merge([
        'type' => 'nice_to_have',
        'field' => 'skills',
        'operator' => 'contains_any',
        'target' => ['values' => ['PLC']],
        'weight' => 40,
        'label_ar' => 'خبرة في PLC',
        'label_en' => 'PLC experience',
        'justification' => 'Lines are PLC-controlled.',
        'legal_basis_note' => null,
    ], $overrides));
}

function criteriaValidator(): CriteriaValidator
{
    return CriteriaValidator::fromConfig();
}

it('accepts a valid weighted nice-to-have criterion', function () {
    expect(criteriaValidator()->validateCriterion(makeCriterion())->passes())->toBeTrue();
});

it('rejects protected attributes as fields with a named error', function (string $field) {
    $result = criteriaValidator()->validateCriterion(makeCriterion(['field' => $field]));

    expect($result->has('field', 'app.criteria.errors.field_protected'))->toBeTrue();
})->with(['religion', 'gender', 'marital_status', 'age', 'photo', 'disability', 'health', 'political_affiliation', 'union_membership', 'nationality', 'national_origin']);

it('rejects location as a criterion (Labour Law 14/2025)', function (string $field) {
    $result = criteriaValidator()->validateCriterion(makeCriterion(['field' => $field]));

    expect($result->has('field', 'app.criteria.errors.field_protected'))->toBeTrue();
})->with(['governorate', 'city', 'address', 'residence', 'home_city']);

it('offers the job-related reach-the-plant criterion instead of location', function () {
    $result = criteriaValidator()->validateCriterion(makeCriterion([
        'type' => 'must_have', 'field' => 'can_reach_plant_for_shift', 'operator' => 'is_explicitly_true',
        'target' => [], 'weight' => null,
        'label_ar' => 'القدرة على الوصول للمصنع في مواعيد الوردية', 'label_en' => 'Can reach the plant for the shift times',
    ]));

    expect($result->passes())->toBeTrue();
});

it('rejects protected or location wording in labels and targets', function (array $overrides) {
    $result = criteriaValidator()->validateCriterion(makeCriterion($overrides));

    expect($result->keys())->toContain('app.criteria.errors.protected_terms');
})->with([
    'english males only' => [['label_en' => 'Males only']],
    'arabic males only' => [['label_ar' => 'ذكور فقط']],
    'governorate in target' => [['target' => ['values' => ['محافظة الشرقية']]]],
    'married' => [['justification' => 'Prefer married candidates']],
    'age wording' => [['label_en' => 'Age under 30']],
]);

it('does not flag ordinary words that contain protected substrings', function () {
    // "السنوات" contains "السن", "maintaining" is not "main…" etc.
    $result = criteriaValidator()->validateCriterion(makeCriterion([
        'label_ar' => 'خبرة بالسنوات في المحافظة على المعدات',
        'label_en' => 'Years of experience maintaining equipment',
    ]));

    expect($result->passes())->toBeTrue();
});

it('allows the legal minimum-age check only with a legal basis', function () {
    $base = [
        'type' => 'must_have', 'field' => 'legal_minimum_age_confirmed', 'operator' => 'is_explicitly_true',
        'target' => [], 'weight' => null,
        'label_ar' => 'استيفاء السن القانونية للعمل', 'label_en' => 'Meets the legal minimum working age',
    ];

    expect(criteriaValidator()->validateCriterion(makeCriterion($base))->has('legal_basis_note'))->toBeTrue()
        ->and(criteriaValidator()->validateCriterion(makeCriterion($base + ['legal_basis_note' => 'Labour law minimum age; approved by counsel']))->passes())->toBeTrue();
});

it('requires a legal basis for disqualifiers and military status', function (array $overrides) {
    expect(criteriaValidator()->validateCriterion(makeCriterion($overrides))->has('legal_basis_note', 'app.criteria.errors.legal_basis_required'))->toBeTrue();
})->with([
    'disqualifier' => [['type' => 'disqualifier', 'weight' => null, 'field' => 'certifications']],
    'military' => [['type' => 'must_have', 'weight' => null, 'field' => 'military_status', 'operator' => 'in', 'target' => ['values' => ['completed', 'exempted']]]],
]);

it('validates weights by criterion type', function (array $overrides, string $key) {
    expect(criteriaValidator()->validateCriterion(makeCriterion($overrides))->has('weight', $key))->toBeTrue();
})->with([
    'missing weight' => [['weight' => null], 'app.criteria.errors.weight_range'],
    'zero weight' => [['weight' => 0], 'app.criteria.errors.weight_range'],
    'too high' => [['weight' => 101], 'app.criteria.errors.weight_range'],
    'non numeric' => [['weight' => 'abc'], 'app.criteria.errors.weight_range'],
    'gate with weight' => [['type' => 'must_have', 'weight' => 10], 'app.criteria.errors.weight_not_allowed'],
]);

it('rejects operators that do not fit the field and malformed targets', function (array $overrides, string $attribute) {
    expect(criteriaValidator()->validateCriterion(makeCriterion($overrides))->has($attribute))->toBeTrue();
})->with([
    'gte on skills' => [['operator' => 'gte', 'target' => ['value' => 3]], 'operator'],
    'unknown operator' => [['operator' => 'regex'], 'operator'],
    'empty list' => [['target' => ['values' => []]], 'target'],
    'negative years' => [['field' => 'experience_total_years', 'operator' => 'gte', 'target' => ['value' => -1]], 'target'],
    'inverted range' => [['field' => 'experience_total_years', 'operator' => 'between', 'target' => ['min' => 5, 'max' => 2]], 'target'],
    'unknown level' => [['field' => 'education_level', 'operator' => 'gte', 'target' => ['value' => 'phd-ish']], 'target'],
    'unknown field' => [['field' => 'favourite_colour'], 'field'],
]);

it('requires both labels and a job-relevance justification', function () {
    $result = criteriaValidator()->validateCriterion(makeCriterion(['label_ar' => '', 'label_en' => ' ', 'justification' => '']));

    expect($result->has('label_ar'))->toBeTrue()
        ->and($result->has('label_en'))->toBeTrue()
        ->and($result->has('justification'))->toBeTrue();
});

it('blocks approval without a weighted nice-to-have criterion', function () {
    $result = criteriaValidator()->validateVersion([
        makeCriterion(['type' => 'must_have', 'weight' => null, 'field' => 'shift_availability', 'operator' => 'is_explicitly_true', 'target' => []]),
    ]);

    expect($result->keys())->toContain('app.criteria.errors.version_needs_weighted');
});

it('detects conflicting and duplicate criteria in a version', function () {
    $mustAtLeast5 = makeCriterion(['type' => 'must_have', 'weight' => null, 'field' => 'experience_total_years', 'operator' => 'gte', 'target' => ['value' => 5]]);
    $mustAtMost3 = makeCriterion(['type' => 'must_have', 'weight' => null, 'field' => 'experience_total_years', 'operator' => 'lte', 'target' => ['value' => 3]]);

    $result = criteriaValidator()->validateVersion([makeCriterion(), makeCriterion(), $mustAtLeast5, $mustAtMost3]);

    expect($result->keys())->toContain('app.criteria.errors.conflict_range')
        ->and($result->keys())->toContain('app.criteria.errors.conflict_duplicate');
});

it('accepts the illustrative spec §7.5 version', function () {
    $result = criteriaValidator()->validateVersion([
        makeCriterion(['type' => 'must_have', 'weight' => null, 'field' => 'education_level', 'operator' => 'gte', 'target' => ['value' => 'technical_diploma']]),
        makeCriterion(['type' => 'must_have', 'weight' => null, 'field' => 'experience_relevant_years', 'operator' => 'gte', 'target' => ['value' => 2]]),
        makeCriterion(['type' => 'must_have', 'weight' => null, 'field' => 'shift_availability', 'operator' => 'is_explicitly_true', 'target' => []]),
        makeCriterion(['weight' => 40]),
        makeCriterion(['weight' => 25, 'target' => ['values' => ['schematics']]]),
    ]);

    expect($result->passes())->toBeTrue();
});
