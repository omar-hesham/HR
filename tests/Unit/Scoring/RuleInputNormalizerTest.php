<?php

use App\Domain\Criteria\CriterionData;
use App\Domain\Scoring\RuleInputNormalizer;
use App\Domain\Scoring\RulesEngine;

function comparisonNormalizer(): RuleInputNormalizer
{
    return new RuleInputNormalizer(config('recruitment.comparison_vocabulary'), config('ai-rules.synonyms'), config('recruitment.qualification_levels'));
}

function comparisonFact(mixed $value): array
{
    return ['value' => $value, 'confidence' => .95, 'source' => 'model', 'evidence' => [['quote' => 'VERBATIM evidence', 'document_id' => 9, 'start' => 4, 'end' => 21]]];
}

it('maps only comparison values and records the untouched original', function ($field, $raw, $expected, $targets = []) {
    $fact = comparisonFact($raw);
    $result = comparisonNormalizer()->normalize($field, $fact, $targets)[0];
    expect($result['value'])->toBe($expected)->and($result['normalized_from'])->toBe($raw)
        ->and($result['evidence'])->toBe($fact['evidence'])->and($result['confidence'])->toBe(.95)
        ->and($result['source'])->toBe('model')->and($fact)->not->toHaveKey('normalized_from');
})->with([
    ['education_level', 'دبلوم فني', 'technical_diploma'],
    ['education_level', 'دبلوم صنايع', 'technical_diploma'],
    ['education_level', 'دِبْلُوم صِنَاعِى', 'technical_diploma'],
    ['education_level', 'Technical Diploma', 'technical_diploma'],
    ['education_level', 'diploma', 'technical_diploma'],
    ['education_level', 'بَكالوريوس', 'bachelor'],
    ['education_level', "Bachelor's degree", 'bachelor'],
    ['education_level', 'postgraduate diploma', 'postgraduate_diploma'],
    ['education_level', 'ماجستير', 'master'],
    ['education_level', 'دكتوراه', 'doctorate'],
    ['education_field', 'كهرباء', 'electrical'],
    ['education_field', 'هَنْدَسَة كَهْرَبَائِيَّة', 'electrical'],
    ['skills', 'تشغيل أنظمة التحكم PLC', 'plc'],
    ['skills', 'Experienced with ＰＬＣ', 'plc'],
    ['skills', 'قراءة المخططات الكهربائية (schematics)', 'schematics'],
    ['certifications', 'Electrical safety training', 'electrical safety', ['electrical safety']],
    ['certifications', 'تدريب السلامة الكهربائية', 'electrical safety', ['electrical safety']],
    ['certifications', 'السلامة الكهربائية', 'electrical safety', ['electrical safety']],
    ['certifications', 'Electrical safety training', 'safety_training', ['safety_training']],
    ['shift_availability', 'مستعد للعمل بنظام الورديات', 'available'],
    ['shift_availability', 'Willing to work rotating shifts', 'available'],
    ['shift_availability', 'YES', 'available'],
    ['shift_availability', 'نَعَمْ', 'available'],
    ['shift_availability', 'Not willing to work rotating shifts', 'not_available'],
    ['shift_availability', 'لا أستطيع العمل بالورديات', 'not_available'],
    ['shift_availability', 'غير مُستعد للعمل بنظام الورديات', 'not_available'],
    ['shift_availability', 'No', 'not_available'],
    ['shift_availability', 'Cannot work rotating shifts', 'not_available'],
    ['shift_availability', 'Not available for rotating shifts', 'not_available'],
    ['shift_availability', 'غير متاح للعمل بنظام الورديات', 'not_available'],
    ['shift_availability', 'لست مستعد للعمل بنظام الورديات', 'not_available'],
]);

it('leaves unknown ambiguous and partial token values raw', function ($field, $value) {
    $fact = comparisonFact($value);
    expect(comparisonNormalizer()->normalize($field, $fact))->toBe([$fact]);
})->with([
    ['education_level', 'unmapped qualification'],
    ['education_level', 'bachelor or technical diploma'],
    ['skills', 'plcplus'],
    ['skills', 'microschematics'],
    ['skills', 'motivated'],
    ['shift_availability', 'not sure whether willing to work rotating shifts'],
    ['shift_availability', 'Not available for shift work is not what I said'],
    ['shift_availability', 'yes to relocation'],
    ['shift_availability', 'I accept work at the specified location'],
    ['shift_availability', 'مستعد للعمل بنظام الورديات إذا توفرت مواصلات'],
    ['shift_availability', 'Willing to work shifts if transport is provided'],
]);

it('keeps protected identity and location fields outside the normalization allowlist', function () {
    foreach ([...config('recruitment.protected.fields'), 'name', 'contacts', 'candidate_city'] as $field) {
        $fact = comparisonFact('Willing to work rotating shifts');
        expect(comparisonNormalizer()->normalize($field, $fact))->toBe([$fact]);
        $criterion = CriterionData::fromArray(['field' => $field, 'type' => 'must_have', 'operator' => 'is_explicitly_true', 'target' => []]);
        $out = (new RulesEngine)->evaluate([$criterion], [$field => $fact], '1', true, m3Snapshot());
        expect($out['results'][0]['result'])->toBe('unclear');
    }
    $fact = comparisonFact('yes');
    expect(comparisonNormalizer()->normalize('can_reach_plant_for_shift', $fact))->toBe([$fact]);
});

it('supports multiple explicit skills without matching substrings or inventing a meaning for shared aliases', function () {
    $facts = comparisonNormalizer()->normalize('skills', comparisonFact('PLC and reading schematics'), ['plc', 'schematics']);
    expect(array_column($facts, 'value'))->toBe(['plc', 'schematics']);
    $normalizer = new RuleInputNormalizer(config('recruitment.comparison_vocabulary'), ['plc' => ['control'], 'schematics' => ['control']], config('recruitment.qualification_levels'));
    $fact = comparisonFact('control');
    expect($normalizer->normalize('skills', $fact))->toBe([$fact]);
});

it('preserves missing evidence verification failures and confidence gates after normalization', function ($change) {
    $facts = m3Facts();
    $facts['skills'][0] = array_replace($facts['skills'][0], ['value' => 'تشغيل أنظمة التحكم PLC'], $change);
    $out = (new RulesEngine)->evaluate(m3Criteria(), $facts, '1', true, m3Snapshot());
    expect($out['results'][3]['result'])->toBe('unclear');
})->with([
    [['evidence' => []]],
    [['verification_error' => 'quote_not_found']],
    [['confidence' => .79]],
]);

it('keeps refusal negative and uncertainty unresolved using verified quote text', function ($value, $quote, $expected) {
    $facts = m3Facts();
    $facts['shift_availability']['value'] = $value;
    $facts['shift_availability']['evidence'] = [['quote' => $quote, 'document_id' => 1, 'start' => 0, 'end' => mb_strlen($quote)]];
    $out = (new RulesEngine)->evaluate(m3Criteria(), $facts, '1', true, m3Snapshot());
    expect($out['results'][2]['result'])->toBe($expected);
})->with([
    ['Not willing to work rotating shifts', 'Not willing to work rotating shifts', 'not_met'],
    ['لا أستطيع العمل بالورديات', 'لا أستطيع العمل بالورديات', 'not_met'],
    ['available', 'Not sure whether available for shift work', 'unclear'],
    ['available', 'I accept work at the specified location', 'unclear'],
]);

it('leaves unknown qualifications unclear and unknown skills not mentioned', function () {
    $facts = m3Facts();
    $facts['education'][0]['level']['value'] = 'unmapped qualification';
    $facts['skills'][0]['value'] = 'plcplus';
    $out = (new RulesEngine)->evaluate(m3Criteria(), $facts, '1', true, m3Snapshot());
    expect($out['results'][0]['result'])->toBe('unclear')->and($out['results'][3]['result'])->toBe('not_mentioned');
});

it('does not turn a negated qualification into a confirmed qualification', function ($raw, $quote) {
    $fact = comparisonFact($raw);
    $fact['evidence'][0]['quote'] = $quote;
    expect(comparisonNormalizer()->normalize('education_level', $fact))->toBe([$fact]);
})->with([
    ['No technical diploma', 'No technical diploma'],
    ['دبلوم فني', 'لا أحمل دبلوم فني'],
    ['Technical diploma', 'No technical diploma'],
    ['بدون دبلوم فني', 'بدون دبلوم فني'],
]);

it('retains negative Arabic evidence even with diacritics and normalized skill names', function () {
    $facts = m3Facts();
    $facts['skills'][2]['value'] = 'صيانة ماكينات سحب الأسلاك';
    $quote = 'لَمْ أعمل على ماكينات سحب الأسلاك';
    $facts['skills'][2]['evidence'] = [['quote' => $quote, 'document_id' => 1, 'start' => 0, 'end' => mb_strlen($quote)]];
    $out = (new RulesEngine)->evaluate(m3Criteria(), $facts, '1', true, m3Snapshot());
    expect($out['results'][6]['result'])->toBe('not_met')->and($out['results'][6]['evidence'][0]['quote'])->toBe($quote);
});
