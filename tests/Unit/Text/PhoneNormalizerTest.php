<?php

/**
 * TXT-P0-04: Egyptian phones → E.164 "+20…", original kept, never guessed (synthetic numbers).
 */

use App\Domain\Text\PhoneNormalizer;
use App\Domain\Text\PhoneNumberResult;

dataset('phones', [
    'mobile national' => ['01001234567', '+201001234567', PhoneNumberResult::STATUS_EGYPT],
    'mobile with spaces' => ['0100 123 4567', '+201001234567', PhoneNumberResult::STATUS_EGYPT],
    'mobile with dashes and parentheses' => ['(012) 0000-1003', '+201200001003', PhoneNumberResult::STATUS_EGYPT],
    'eastern digits' => ['٠١٠٠١٢٣٤٥٦٧', '+201001234567', PhoneNumberResult::STATUS_EGYPT],
    'already international' => ['+20 100 123 4567', '+201001234567', PhoneNumberResult::STATUS_EGYPT],
    '00 prefix' => ['0020 100 123 4567', '+201001234567', PhoneNumberResult::STATUS_EGYPT],
    'cairo landline' => ['02 2345 6789', '+20223456789', PhoneNumberResult::STATUS_EGYPT],
    'saudi number keeps its country' => ['+966 50 123 4567', '+966501234567', PhoneNumberResult::STATUS_INTERNATIONAL],
    'too short' => ['0100123', null, PhoneNumberResult::STATUS_INVALID],
    'missing trunk zero is not guessed' => ['1001234567', null, PhoneNumberResult::STATUS_AMBIGUOUS],
    'letters' => ['call me', null, PhoneNumberResult::STATUS_INVALID],
]);

it('normalizes Egyptian and international numbers without guessing', function (string $input, ?string $e164, string $status) {
    $result = PhoneNormalizer::make()->normalize($input);

    expect($result->e164)->toBe($e164)
        ->and($result->status)->toBe($status)
        ->and($result->original)->toBe($input);
})->with('phones');

it('never prefixes +20 onto a foreign number', function () {
    $result = PhoneNormalizer::make()->normalize('+44 20 7946 0958');

    expect($result->e164)->toStartWith('+44');
});
