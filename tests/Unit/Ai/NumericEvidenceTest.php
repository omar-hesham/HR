<?php

use App\Domain\Ai\AiException;
use App\Domain\Ai\EvidenceVerifier;
use App\Domain\Ai\FactSupportVerifier;

it('does not confirm a numeric value invented alongside a real quote', function () {
    $p = m3Facts();
    $p['experience_total_years']['value'] = 30;
    $out = (new FactSupportVerifier)->verify($p);
    expect($out['experience_total_years']['confidence'])->toBe(0)->and($out['experience_total_years']['verification_error'])->toBe('value_not_supported');
});
it('unions overlapping complete periods and refuses incomplete or ambiguous dates', function () {
    $f = fn ($v) => ['value' => $v, 'confidence' => .9, 'evidence' => [['quote' => $v]]];
    $roles = [['start_date' => $f('2020-01-01'), 'end_date' => $f('2022-01-01')], ['start_date' => $f('2021-01-01'), 'end_date' => $f('2023-01-01')]];
    expect((new FactSupportVerifier)->employmentUnion($roles))->toBe(3.0);
    $roles[0]['end_date'] = $f('2022');
    expect((new FactSupportVerifier)->employmentUnion($roles))->toBeNull();
});
it('refuses zero-length evidence and impossible page numbers', function ($quote, $page) {
    expect(fn () => (new EvidenceVerifier)->verify(['f' => ['value' => 1, 'confidence' => 1, 'evidence' => [['quote' => $quote, 'page' => $page]]]], 'x', 1))->toThrow(AiException::class, 'InvalidSchema');
})->with([['', 1], ['x', 2]]);
