<?php

namespace App\Domain\Intake;

/**
 * Result of classifying one attachment: its class plus the stable reason code.
 */
final class ClassifiedAttachment
{
    public function __construct(
        public readonly AttachmentClass $class,
        public readonly string $reason,
    ) {}
}
