<?php

use App\Domain\Criteria\CriterionData;
use App\Domain\Scoring\RulesEngine;

it('reproduces all eight worked outcomes S65 C80 and deterministic identical results', function () {
    $engine = new RulesEngine;
    $a = $engine->evaluate(m3Criteria(), m3Facts(), '1', true, m3Snapshot());
    $b = $engine->evaluate(m3Criteria(), m3Facts(), '1', true, m3Snapshot());
    expect($a)->toBe($b)->and($a['score'])->toBe(65.0)->and($a['coverage'])->toBe(80.0)->and($a['band'])->toBe('needs_review')->and(array_column($a['results'], 'result'))->toBe(['met', 'met', 'met', 'met', 'met', 'not_mentioned', 'not_met', 'not_met']);
});
it('keeps a missing mandatory gate in review even with S100', function () {
    $facts = m3Facts();
    $facts['shift_availability'] = ['value' => null, 'confidence' => 0, 'evidence' => []];
    $facts['certifications'] = [['value' => 'safety_training', 'confidence' => 1, 'evidence' => [['quote' => 'Electrical safety training']], 'source' => 'human_correction']];
    $facts['skills'][2] = ['value' => 'wire_drawing', 'confidence' => 1, 'evidence' => [['quote' => 'Confirmed experience']], 'source' => 'human_answer'];
    $out = (new RulesEngine)->evaluate(m3Criteria(), $facts, '1', true, m3Snapshot());
    expect($out['score'])->toBe(100.0)->and($out['band'])->toBe('needs_review');
});
it('never infers zero experience and ignores unverified quotes', function () {
    $facts = m3Facts();
    $facts['experience_relevant_years'][0]['years'] = ['value' => null, 'confidence' => 0, 'evidence' => []];
    $facts['skills'][0]['confidence'] = 0;
    $facts['skills'][0]['verification_error'] = 'quote_not_found';
    $out = (new RulesEngine)->evaluate(m3Criteria(), $facts, '1', true, m3Snapshot());
    expect($out['results'][1]['result'])->toBe('not_mentioned')->and($out['results'][3]['result'])->toBe('unclear')->and($out['band'])->toBe('needs_review');
});
it('lets a confirmed disqualifier dominate S without producing an application status', function () {
    $facts = m3Facts();
    $facts['can_reach_plant_for_shift']['value'] = false;
    $out = (new RulesEngine)->evaluate(m3Criteria(), $facts, '1', true, m3Snapshot());
    expect($out['band'])->toBe('weak_match')->and($out['review'])->toBeTrue()->and($out)->not->toHaveKey('status');
});
it('returns configuration review for an unapproved version, absent snapshot or no weighted preferences', function ($criteria, $approved, $snapshot) {
    $out = (new RulesEngine)->evaluate($criteria, m3Facts(), '1', $approved, $snapshot);
    expect($out['score'])->toBeNull()->and($out['band'])->toBe('configuration_review');
})->with(['unapproved' => fn () => [m3Criteria(), false, m3Snapshot()], 'no weighted' => fn () => [array_slice(m3Criteria(), 0, 3), true, m3Snapshot()], 'no snapshot' => fn () => [m3Criteria(), true, []]]);
it('does not borrow job-specific years or infer commuting from a model', function () {
    $facts = m3Facts();
    $facts['can_reach_plant_for_shift']['source'] = 'model';
    $out = (new RulesEngine)->evaluate(m3Criteria(), $facts, '2', true, m3Snapshot());
    expect($out['results'][1]['result'])->toBe('not_mentioned')->and($out['results'][7]['result'])->toBe('not_mentioned');
});
it('matches bilingual synonyms and name or font variants equally', function () {
    $engine = new RulesEngine;
    $facts = m3Facts();
    $baseline = $engine->evaluate(m3Criteria(), $facts, '1', true, m3Snapshot());
    $facts['skills'][0]['value'] = 'وحدات التحكم المنطقي';
    $facts['education'][0]['level']['value'] = 'دبلوم صناعي';
    $facts['name'] = ['value' => 'Hassan Samir'];
    $variant = $engine->evaluate(m3Criteria(), $facts, '1', true, m3Snapshot());
    expect($variant['score'])->toBe($baseline['score'])->and($variant['coverage'])->toBe($baseline['coverage'])->and(array_column($variant['results'], 'result'))->toBe(array_column($baseline['results'], 'result'));
});
it('compares salary only in the configured currency and period', function () {
    $c = CriterionData::fromArray(['type' => 'nice_to_have', 'field' => 'expected_salary', 'operator' => 'lte', 'target' => ['value' => 10000], 'weight' => 1]);
    $f = fn ($v) => ['value' => $v, 'confidence' => 1, 'evidence' => [['quote' => 'Explicit salary']], 'source' => 'human_answer'];
    $facts = ['expected_salary' => ['amount' => $f(5000), 'currency' => $f('USD'), 'period' => $f('monthly')]];
    $snapshot = m3Snapshot() + ['salary_currency' => 'EGP', 'salary_period' => 'monthly'];
    $out = (new RulesEngine)->evaluate([$c], $facts, '1', true, $snapshot);
    expect($out['results'][0]['result'])->toBe('unclear');
    $facts['expected_salary']['currency'] = $f('EGP');
    $out = (new RulesEngine)->evaluate([$c], $facts, '1', true, $snapshot);
    expect($out['results'][0]['result'])->toBe('met');
});

it('requires electrical specialization for the illustrative C1 rather than an unrelated diploma', function () {
    $facts = m3Facts();
    $facts['education'][0]['field']['value'] = 'mechanical';
    $out = (new RulesEngine)->evaluate(m3Criteria(), $facts, '1', true, m3Snapshot());
    expect($out['results'][0]['result'])->toBe('not_met')->and($out['band'])->toBe('weak_match');
});
it('does not infer shift availability from acceptance of a location or unrelated negative experience', function () {
    $facts = m3Facts();
    $facts['shift_availability']['evidence'] = [['quote' => 'I accept work at the specified location', 'document_id' => 1, 'start' => 0, 'end' => 42]];
    $out = (new RulesEngine)->evaluate(m3Criteria(), $facts, '1', true, m3Snapshot());
    expect($out['results'][2]['result'])->toBe('unclear');
});
it('does not attach another skills evidence to a criterion', function () {
    $out = (new RulesEngine)->evaluate(m3Criteria(), m3Facts(), '1', true, m3Snapshot());
    expect(array_column($out['results'][3]['evidence'], 'quote'))->toBe(['Diagnosing PLC faults'])->and(array_column($out['results'][6]['evidence'], 'quote'))->toBe(['I have not worked on wire-drawing machines']);
});
