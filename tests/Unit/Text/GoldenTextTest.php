<?php

/**
 * T-TXT-01 golden cases (spec §8 TXT-P0-06): search folding, tashkeel/tatweel,
 * presentation forms, Eastern digits, mixed Latin/Arabic, punctuation, astral
 * characters, reversed Arabic, and offsets that stay attributable to the raw text.
 */

use App\Domain\Text\QualityThresholds;
use App\Domain\Text\ReversedTextDetector;
use App\Domain\Text\SearchNormalizer;
use App\Domain\Text\TextNormalizer;
use App\Domain\Text\TextQualityChecker;
use App\Domain\Text\Wordlist;
use App\Infrastructure\Extraction\TextExtractor;

dataset('search_folding', [
    'alef hamza above' => ['أحمد', 'احمد'],
    'alef hamza below' => ['إسلام', 'اسلام'],
    'alef madda' => ['آمال', 'امال'],
    'alef maqsura' => ['على', 'علي'],
    'taa marbuta' => ['فاطمة', 'فاطمه'],
    'tashkeel' => ['مُحَمَّدٌ', 'محمد'],
    'tatweel' => ['مـــحـــمد', 'محمد'],
    'eastern digits' => ['٠١٠٠ ٢٣٤ ٥٦٧٨', '0100 234 5678'],
    'persian digits' => ['۱۲۳', '123'],
    'latin case' => ['PLC Engineer', 'plc engineer'],
    'lam-alef presentation form' => ["\u{FEFB}", 'لا'],
    'whitespace' => ["  سيرة \t ذاتية  ", 'سيره ذاتيه'],
]);

it('folds text for search only', function (string $input, string $expected) {
    expect(SearchNormalizer::normalize($input))->toBe($expected);
})->with('search_folding');

it('treats spelling variants as equal in search', function (string $a, string $b) {
    expect(SearchNormalizer::normalize($a))->toBe(SearchNormalizer::normalize($b));
})->with([
    ['أحمد', 'احمد'],
    ['على', 'علي'],
    ['فاطمة', 'فاطمه'],
]);

it('repairs Arabic presentation forms with NFKC and keeps letters otherwise unchanged', function () {
    // "السيرة" written with presentation-form glyphs, as pdftotext emits from some PDFs.
    $raw = "\u{FE8D}\u{FEDF}\u{FEB4}\u{FEF4}\u{FEAE}\u{FE93}";
    $normalized = (new TextNormalizer)->normalize($raw);

    expect($normalized->text)->toBe('السيرة')
        ->and($normalized->presentationFormsReplaced)->toBe(6);
});

it('expands the lam-alef ligature and keeps the offset map attributable to the raw text', function () {
    $raw = "\u{FEFB} عمل";
    $normalized = (new TextNormalizer)->normalize($raw);

    expect($normalized->text)->toBe('لا عمل')
        // both letters of the expanded ligature point to raw index 0
        ->and($normalized->toRawOffset(0))->toBe(0)
        ->and($normalized->toRawOffset(1))->toBe(0)
        // "ع" is normalized index 3 and raw index 2
        ->and($normalized->toRawOffset(3))->toBe(2);
});

it('keeps tashkeel, tatweel and alef variants in the evidence text (only search folds them)', function () {
    $text = 'مُـهندس أحمد على';

    expect((new TextNormalizer)->normalize($text)->text)->toBe($text);
});

it('keeps mixed Arabic, Latin, email, parentheses and dashes in logical order', function () {
    $text = 'خبرة PLC (Siemens S7) - البريد: karim.synthetic@example.test';
    $normalized = (new TextNormalizer)->normalize($text);

    expect($normalized->text)->toBe($text)
        ->and(SearchNormalizer::normalize($text))->toContain('karim.synthetic@example.test')
        ->and(SearchNormalizer::normalize($text))->toContain('(siemens s7)');
});

it('handles a character outside the Basic Multilingual Plane by code point, not bytes', function () {
    $text = 'فني 😀 كهرباء';
    $normalized = (new TextNormalizer)->normalize($text);

    expect($normalized->length())->toBe(12)
        ->and(mb_substr($normalized->text, 4, 1))->toBe('😀')
        ->and($normalized->toRawOffset(6))->toBe(6);
});

it('cleans whitespace and bidi controls but keeps page breaks', function () {
    $raw = "\u{202B}سطر   أول\u{202C}  \r\n\r\n\r\n\r\nسطر ثان\f";
    $normalized = (new TextNormalizer)->normalize($raw);

    expect($normalized->text)->toBe("سطر أول\n\nسطر ثان");
});

it('never reverses Arabic text, it only flags it', function () {
    $reversed = 'ةسدنهلا'; // "الهندسة" in visual order
    $normalized = (new TextNormalizer)->normalize($reversed);
    $detector = new ReversedTextDetector(Wordlist::default());

    expect($normalized->text)->toBe($reversed)
        ->and($detector->isSuspected(SearchNormalizer::normalize($reversed)))->toBeTrue()
        ->and($detector->isSuspected(SearchNormalizer::normalize('الهندسة')))->toBeFalse();
});

it('keeps two-column and table text extracted from the fixtures', function () {
    requirePdftotext();

    $text = app(TextExtractor::class)->extract(cvFixture('05_table_layout.pdf'), 'pdf')->text;
    $normalized = (new TextNormalizer)->normalize($text)->text;
    $report = (new TextQualityChecker(Wordlist::default()))->check($normalized, new QualityThresholds);

    expect(SearchNormalizer::normalize($normalized))->toContain('تجاره')
        ->and(SearchNormalizer::normalize($normalized))->toContain('شعبه محاسبه')
        ->and($normalized)->toContain('heba.synthetic@example.test')
        // presentation forms are all repaired by NFKC
        ->and(preg_match('/[\x{FB50}-\x{FDFF}\x{FE70}-\x{FEFF}]/u', $normalized))->toBe(0)
        // the synthetic PDF generator drops some glyph mappings (e.g. medial kaf): reported, never guessed
        ->and($report->unmappedGlyphs)->toBeGreaterThan(0)
        ->and($report->passed())->toBeTrue();
});
