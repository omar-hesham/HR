<?php

use App\Domain\Ai\AiException;
use App\Domain\Ai\EvidenceVerifier;
use App\Domain\Ai\Schema;
use Illuminate\Support\Arr;

it('accepts the versioned synthetic schema contract', function () {
    (new Schema)->validate(m3Response());
    expect(true)->toBeTrue();
});
it('rejects contract violations without trusting model offsets', function ($mutate) {
    $payload = $mutate(m3Response());
    expect(fn () => (new Schema)->validate($payload))->toThrow(AiException::class, 'InvalidSchema');
})->with([
    'extra field' => fn () => fn ($p) => $p + ['score' => 100],
    'missing required' => fn () => function ($p) {
        unset($p['skills']);

        return $p;
    },
    'wrong version' => fn () => function ($p) {
        $p['schema_version'] = '2.0';

        return $p;
    },
    'model offsets' => fn () => function ($p) {
        $p['skills'][0]['evidence'][0]['start'] = 0;

        return $p;
    },
    'document id' => fn () => function ($p) {
        $p['skills'][0]['evidence'][0]['document_id'] = 1;

        return $p;
    },
    'confidence above one' => fn () => function ($p) {
        $p['skills'][0]['confidence'] = 1.01;

        return $p;
    },
    'negative confidence' => fn () => function ($p) {
        $p['skills'][0]['confidence'] = -.01;

        return $p;
    },
    'numeric strings' => fn () => function ($p) {
        $p['skills'][0]['confidence'] = '0.95';

        return $p;
    },
    'protected attribute' => fn () => fn ($p) => $p + ['gender' => null],
    'location value' => fn () => function ($p) {
        $p['city'] = ['value' => 'Cairo', 'confidence' => 1, 'evidence' => []];

        return $p;
    },
]);
it('anchors Arabic quotes in Unicode code points rather than bytes', function () {
    $text = '😀 خبرة في الصيانة الكهربائية';
    $quote = 'الصيانة الكهربائية';
    $fact = ['value' => 'electrical', 'confidence' => .9, 'evidence' => [['quote' => $quote]]];
    $out = (new EvidenceVerifier)->verify(['field' => $fact], $text, 42)['field'];
    $e = $out['evidence'][0];
    expect($e['start'])->toBe(mb_strpos($text, $quote))->and($e['end'])->toBe($e['start'] + mb_strlen($quote))->and($e['end'])->toBeGreaterThan($e['start'])->and($e['document_id'])->toBe(42)->and(mb_substr($text, $e['start'], $e['end'] - $e['start']))->toBe($quote);
});
it('resolves repeated quotes by page and sends unresolved attribution to review', function () {
    $fact = ['value' => 3, 'confidence' => .95, 'evidence' => [['quote' => 'خبرة صيانة']]];
    $text = "خبرة صيانة\fخبرة صيانة";
    $out = (new EvidenceVerifier)->verify(['f' => $fact], $text, 1)['f'];
    expect($out['confidence'])->toBe(0)->and($out['verification_error'])->toBe('quote_ambiguous');
    $fact['evidence'][0]['page'] = 2;
    $out = (new EvidenceVerifier)->verify(['f' => $fact], $text, 1)['f'];
    expect($out['evidence'][0]['start'])->toBe(mb_strlen("خبرة صيانة\f"));
});
it('never confirms nonexistent evidence or substitutes search-normalized text', function () {
    $fact = ['value' => 2, 'confidence' => .99, 'evidence' => [['quote' => 'احمد']]];
    $out = (new EvidenceVerifier)->verify(['f' => $fact], 'أحمد', 1)['f'];
    expect($out['confidence'])->toBe(0)->and($out['verification_error'])->toBe('quote_not_found')->and($out['evidence'])->toBe([]);
});
it('restores masking before locating evidence and preserves null as not mentioned', function () {
    $facts = ['f' => ['value' => 'plc', 'confidence' => .9, 'evidence' => [['quote' => '[PII_00001] operates PLC']]], 'missing' => ['value' => null, 'confidence' => 0, 'evidence' => []]];
    $out = (new EvidenceVerifier)->verify($facts, 'Samir operates PLC', 3, ['[PII_00001]' => 'Samir']);
    expect($out['f']['evidence'][0]['quote'])->toBe('Samir operates PLC')->and($out['missing']['value'])->toBeNull();
});
it('rejects populated facts lacking evidence', function () {
    expect(fn () => (new EvidenceVerifier)->verify(['f' => ['value' => 3, 'confidence' => 1, 'evidence' => []]], 'text', 1))->toThrow(AiException::class);
});

it('has identical AI translation keys in Arabic and English', function () {
    $arabic = Arr::dot(require lang_path('ar/ai.php'));
    $english = Arr::dot(require lang_path('en/ai.php'));
    expect(array_keys($arabic))->toBe(array_keys($english));
});
