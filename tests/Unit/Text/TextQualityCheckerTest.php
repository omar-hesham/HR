<?php

/**
 * T-M2-03: thresholds above, below and at the limit; reversed order; image-only.
 */

use App\Domain\Documents\ReviewReason;
use App\Domain\Text\QualityThresholds;
use App\Domain\Text\TextQualityChecker;
use App\Domain\Text\Wordlist;

function qualityChecker(): TextQualityChecker
{
    return new TextQualityChecker(new Wordlist(['خبرة', 'صيانة', 'كهرباء', 'مهندس', 'العمل', 'engineer', 'maintenance', 'electrical', 'experience', 'years']));
}

it('passes clean Arabic and English text', function () {
    $text = str_repeat('خبرة صيانة كهرباء مهندس العمل maintenance electrical engineer experience years ', 3);

    $report = qualityChecker()->check($text, new QualityThresholds(minLetters: 10));

    expect($report->passed())->toBeTrue()
        ->and($report->wordlistRatio)->toBe(1.0)
        ->and($report->reversedRatio)->toBe(0.0);
});

it('accepts a value exactly at the wordlist threshold and rejects just below it', function () {
    // 2 known of 5 alphabetic tokens = 0.40
    $atLimit = 'خبرة صيانة qwxz plmk vbnr';
    // 1 known of 5 = 0.20
    $below = 'خبرة zzqa qwxz plmk vbnr';
    $thresholds = new QualityThresholds(minLetterRatio: 0.5, minWordlistRatio: 0.40, maxReversedRatio: 0.2, minLetters: 5);

    expect(qualityChecker()->check($atLimit, $thresholds)->passed())->toBeTrue()
        ->and(qualityChecker()->check($below, $thresholds)->reason)->toBe(ReviewReason::LowQuality);
});

it('counts the letter ratio over non-whitespace characters', function () {

    $text = 'خبرة####'; // 4 letters of 8 non-whitespace characters
    $report = qualityChecker()->check($text, new QualityThresholds(minLetterRatio: 0.60, minWordlistRatio: 0.0, minLetters: 1));

    expect($report->letterRatio)->toBe(0.5)
        ->and($report->failures)->toContain('min_letter_ratio')
        ->and($report->reason)->toBe(ReviewReason::LowQuality);
});

it('flags reversed Arabic above the reversed threshold', function () {
    $reversed = 'ةربخ ةنايص ءابرهك سدنهم';
    $report = qualityChecker()->check($reversed, new QualityThresholds(minLetters: 5));

    expect($report->reversedRatio)->toBe(1.0)
        ->and($report->reason)->toBe(ReviewReason::SuspectedReversed);
});

it('accepts reversed tokens exactly at the reversed threshold', function () {
    // 1 reversed of 5 tokens = 0.20 (limit is inclusive)
    $text = 'ةربخ صيانة كهرباء مهندس العمل';
    $report = qualityChecker()->check($text, new QualityThresholds(minWordlistRatio: 0.4, maxReversedRatio: 0.20, minLetters: 5));

    expect($report->reversedRatio)->toBe(0.2)
        ->and($report->passed())->toBeTrue();
});

it('routes text with too few letters to review as image-only', function () {
    $report = qualityChecker()->check("12 34\n", new QualityThresholds);

    expect($report->reason)->toBe(ReviewReason::ImageOnly);
});

it('ignores digits and single letters in the token count', function () {
    $report = qualityChecker()->check('خبرة 2019 2020 و صيانة', new QualityThresholds(minLetters: 1));

    expect($report->alphaTokens)->toBe(2);
});

it('matches Arabic words with clitics and suffixes', function () {
    $wordlist = new Wordlist(['مهندس', 'صيانة']);

    expect($wordlist->contains('والمهندسين'))->toBeTrue()
        ->and($wordlist->contains('بالصيانه'))->toBeTrue()
        ->and($wordlist->contains('سيارات'))->toBeFalse();
});
