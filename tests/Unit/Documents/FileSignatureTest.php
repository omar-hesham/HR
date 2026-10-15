<?php

use App\Domain\Documents\FileSignature;

it('detects real file types from their bytes', function (string $file, ?string $type) {
    expect(FileSignature::detect((string) file_get_contents(cvFixture($file))))->toBe($type);
})->with([
    ['02_en_accountant.pdf', 'pdf'],
    ['09_ar_storekeeper.docx', 'docx'],
    ['16_legacy.doc', 'doc'],
    ['15_wrong_type.pdf', null],
]);

it('refuses an extension that does not match the content', function () {
    expect(FileSignature::matchesExtension((string) file_get_contents(cvFixture('15_wrong_type.pdf')), 'pdf'))->toBeFalse()
        ->and(FileSignature::matchesExtension((string) file_get_contents(cvFixture('09_ar_storekeeper.docx')), 'pdf'))->toBeFalse()
        ->and(FileSignature::matchesExtension((string) file_get_contents(cvFixture('02_en_accountant.pdf')), 'pdf'))->toBeTrue();
});
