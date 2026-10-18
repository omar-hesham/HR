<?php

use App\Domain\Ai\EvidenceVerifier;
use App\Domain\Ai\Schema;
use App\Domain\Scoring\RulesEngine;

it('gives the recorded free-text model response the same eight outcomes S65 C80 as section 7.5', function () {
    $response = json_decode(file_get_contents(base_path('tests/Fixtures/ai/worked-free-text-response-v1.json')), true, 512, JSON_THROW_ON_ERROR);
    (new Schema)->validate($response);
    $facts = (new EvidenceVerifier)->verify($response, m3Text(), 1);
    $facts['can_reach_plant_for_shift'] = m3Facts()['can_reach_plant_for_shift'];
    $before = $facts;
    $engine = new RulesEngine;
    $canonical = $engine->evaluate(m3Criteria(), m3Facts(), '1', true, m3Snapshot());
    $freeText = $engine->evaluate(m3Criteria(), $facts, '1', true, m3Snapshot());
    expect($freeText['score'])->toBe(65.0)->and($freeText['coverage'])->toBe(80.0)->and($freeText['band'])->toBe('needs_review')
        ->and(array_column($freeText['results'], 'result'))->toBe(array_column($canonical['results'], 'result'))
        ->and(array_column($freeText['results'], 'evidence'))->toBe(array_column($canonical['results'], 'evidence'))
        ->and(array_column($freeText['results'], 'confidence'))->toBe(array_column($canonical['results'], 'confidence'))
        ->and($freeText['results'][0]['normalizations'][0]['normalized_from'])->toBe('دبلوم فني')->and($facts)->toBe($before);
});
