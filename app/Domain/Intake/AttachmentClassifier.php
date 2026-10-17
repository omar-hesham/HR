<?php

namespace App\Domain\Intake;

use App\Domain\Documents\FileSignature;
use App\Domain\Text\SearchNormalizer;

/**
 * Rule-based attachment classification (M1-P0-02/05): extension and true MIME type
 * first, filename keywords only to keep an unknown file out of "other". The AI path
 * for ambiguous cases arrives in Milestone 3; until then ambiguous means review.
 */
final class AttachmentClassifier
{
    public const REASON_CV_FILE_TYPE = 'cv_file_type';

    public const REASON_IMAGE_FILE_TYPE = 'image_file_type';

    public const REASON_FORWARDED_MESSAGE = 'forwarded_message';

    public const REASON_CV_FILENAME_HINT = 'cv_filename_hint';

    public const REASON_UNSUPPORTED_FILE_TYPE = 'unsupported_file_type';

    public const REASON_INLINE_IMAGE = 'inline_image';

    public const REASON_ATTACHMENT_TOO_LARGE = 'attachment_too_large';

    /**
     * @param  list<string>  $cvExtensions
     * @param  list<string>  $imageExtensions
     * @param  list<string>  $cvFilenameTerms  Already search-normalized.
     */
    public function __construct(
        private readonly array $cvExtensions = ['pdf', 'docx', 'doc'],
        private readonly array $imageExtensions = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'bmp', 'tiff'],
        private readonly array $cvFilenameTerms = ['cv', 'resume'],
    ) {}

    public function classify(InboundAttachment $attachment, int $maxBytes): ClassifiedAttachment
    {
        $extension = $attachment->extension();
        $mime = strtolower((string) $attachment->mime);
        $image = in_array($extension, $this->imageExtensions, true) || str_starts_with($mime, 'image/');

        if ($attachment->inline && $image) {
            return new ClassifiedAttachment(AttachmentClass::Other, self::REASON_INLINE_IMAGE);
        }

        if ($attachment->size() > $maxBytes) {
            // Too big to decide automatically: keep it visible for a human (D-106/D-112).
            return new ClassifiedAttachment(AttachmentClass::Uncertain, self::REASON_ATTACHMENT_TOO_LARGE);
        }

        if ($mime === 'message/rfc822' || $attachment->extension() === 'eml') {
            return new ClassifiedAttachment(AttachmentClass::Uncertain, self::REASON_FORWARDED_MESSAGE);
        }

        if (($extension !== null && in_array($extension, $this->cvExtensions, true)) || ($extension === null && FileSignature::detect($attachment->bytes) !== null)) {
            return new ClassifiedAttachment(AttachmentClass::Cv, self::REASON_CV_FILE_TYPE);
        }

        if ($image) {
            return new ClassifiedAttachment(AttachmentClass::Image, self::REASON_IMAGE_FILE_TYPE);
        }

        if ($this->filenameHintsAtCv($attachment->filename)) {
            // Mentions a CV but is not a CV file type: never ignore, let a human decide.
            return new ClassifiedAttachment(AttachmentClass::Uncertain, self::REASON_CV_FILENAME_HINT);
        }

        if (in_array($extension, ['txt', 'csv', 'xls', 'xlsx', 'ppt', 'pptx', 'ics', 'vcf'], true)) {
            return new ClassifiedAttachment(AttachmentClass::Other, self::REASON_UNSUPPORTED_FILE_TYPE);
        }

        return new ClassifiedAttachment(AttachmentClass::Uncertain, 'unknown_attachment_type');
    }

    private function filenameHintsAtCv(?string $filename): bool
    {
        if ($filename === null || trim($filename) === '') {
            return false;
        }

        // Separators (dash, underscore, dot) read as spaces so CV filename phrases match across separators.
        $normalized = SearchNormalizer::normalize((string) preg_replace('/[-_.]+/u', ' ', $filename));

        foreach ($this->cvFilenameTerms as $term) {
            $term = SearchNormalizer::normalize($term);

            if ($term !== '' && str_contains($normalized, $term)) {
                return true;
            }
        }

        return false;
    }
}
