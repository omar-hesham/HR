<?php

use App\Domain\Ai\Masker;

it('masks every candidate token and pattern while retaining a reversible original map', function () {
    $corpus = json_decode(file_get_contents(base_path('tests/Fixtures/ai/masking-corpus.json')), true, 512, JSON_THROW_ON_ERROR)[0];
    $masked = (new Masker)->mask($corpus['text'], sender: $corpus['sender'], filename: $corpus['filename']);
    expect($masked->succeeded)->toBeTrue();
    foreach (['Samir', 'Hassan', 'samir@example.test', '٠١٠١٢٣٤٥٦٧٨', '+44 20 7946 0958', '29801011234567', 'https://example.test/profile', '@samir_h', '12 Test Street'] as $s) {
        expect($masked->text)->not->toContain($s);
    }
    expect(strtr($masked->text, $masked->substitutions))->toBe($corpus['text'])->and($masked->text)->toContain('Nabil Farid');
});
it('rejects conflicting names, uncertain identity, invalid UTF8 and placeholder collisions', function ($text, $sender) {
    expect((new Masker)->mask($text, sender: $sender)->succeeded)->toBeFalse();
})->with([
    ['Name: Samir Hassan', 'Nabil Farid'], ['CV experience education', null], ["\xFF", null], ['Name: Samir Hassan [PII_00001]', null],
]);
it('masks known Arabic name variants with word boundaries and keeps professional substrings', function () {
    $m = (new Masker)->mask("الاسم: أحمد علي\nأحمد احمد علي على عمليات PLC", sender: 'احمد علي', filename: 'أحمد_علي_CV.pdf');
    expect($m->succeeded)->toBeTrue()->and($m->text)->not->toContain('أحمد', 'احمد', ' علي', ' على')->and($m->text)->toContain('عمليات PLC');
});
