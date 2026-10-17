<?php

/**
 * M1-P0-02 / M1-P0-05: rule-based attachment classification.
 */

use App\Domain\Intake\AttachmentClass;
use App\Domain\Intake\AttachmentClassifier;
use App\Domain\Intake\InboundAttachment;

function attachment(array $attributes = []): InboundAttachment
{
    return new InboundAttachment(
        partId: (string) ($attributes['partId'] ?? '1'),
        filename: $attributes['filename'] ?? null,
        mime: $attributes['mime'] ?? null,
        bytes: $attributes['bytes'] ?? str_repeat('x', 100),
        inline: $attributes['inline'] ?? false,
    );
}

it('classifies CV file types by extension', function (string $filename) {
    $classifier = new AttachmentClassifier;

    $result = $classifier->classify(attachment(['filename' => $filename]), 1024 * 1024);

    expect($result->class)->toBe(AttachmentClass::Cv)
        ->and($result->reason)->toBe(AttachmentClassifier::REASON_CV_FILE_TYPE);
})->with([
    ['cv.pdf'],
    ['resume.DOCX'],
    ['legacy.doc'],
]);

it('classifies images separately from CVs', function () {
    $classifier = new AttachmentClassifier;

    $result = $classifier->classify(attachment(['filename' => 'photo.png', 'mime' => 'image/png']), 1024 * 1024);

    expect($result->class)->toBe(AttachmentClass::Image)
        ->and($result->reason)->toBe(AttachmentClassifier::REASON_IMAGE_FILE_TYPE);
});

it('keeps forwarded messages and oversized attachments for a human (uncertain)', function (string $filename, ?string $mime, int $maxBytes) {
    $classifier = new AttachmentClassifier;

    $result = $classifier->classify(attachment(['filename' => $filename, 'mime' => $mime]), $maxBytes);

    expect($result->class)->toBe(AttachmentClass::Uncertain);
})->with([
    'forwarded by mime' => ['forwarded.eml', 'message/rfc822', 1024 * 1024],
    'forwarded by extension' => ['original.msg.eml', null, 1024 * 1024],
    'oversized' => ['huge-cv.pdf', 'application/pdf', 10],
]);

it('does not ignore an unknown file that mentions a CV in its name', function () {
    $classifier = new AttachmentClassifier;

    $result = $classifier->classify(attachment(['filename' => 'my-cv.odt']), 1024 * 1024);

    expect($result->class)->toBe(AttachmentClass::Uncertain)
        ->and($result->reason)->toBe(AttachmentClassifier::REASON_CV_FILENAME_HINT);
});

it('ignores other attachments with a reason', function () {
    $classifier = new AttachmentClassifier;

    $result = $classifier->classify(attachment(['filename' => 'report.xlsx']), 1024 * 1024);

    expect($result->class)->toBe(AttachmentClass::Other)
        ->and($result->reason)->toBe(AttachmentClassifier::REASON_UNSUPPORTED_FILE_TYPE);
});

it('logs inline signature images as ignored, not reviewed', function () {
    $classifier = new AttachmentClassifier;

    $result = $classifier->classify(attachment(['filename' => 'logo.png', 'mime' => 'image/png', 'inline' => true]), 1024 * 1024);

    expect($result->class)->toBe(AttachmentClass::Other)
        ->and($result->reason)->toBe(AttachmentClassifier::REASON_INLINE_IMAGE);
});

it('recognizes Arabic CV filename hints', function () {
    $classifier = new AttachmentClassifier(cvFilenameTerms: ['cv', 'resume', 'سيرة ذاتية']);

    $result = $classifier->classify(attachment(['filename' => 'سيرة-ذاتية.zip']), 1024 * 1024);

    expect($result->class)->toBe(AttachmentClass::Uncertain)
        ->and($result->reason)->toBe(AttachmentClassifier::REASON_CV_FILENAME_HINT);
});
