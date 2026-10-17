<?php

namespace App\Domain\Intake;

/**
 * One parsed MIME attachment. Content is already decoded from its transfer encoding.
 */
final class InboundAttachment
{
    public function __construct(
        public readonly string $partId,
        public readonly ?string $filename,
        public readonly ?string $mime,
        public readonly string $bytes,
        public readonly bool $inline,
    ) {}

    public function size(): int
    {
        return strlen($this->bytes);
    }

    public function extension(): ?string
    {
        $name = $this->filename ?? '';

        if ($name === '') {
            return null;
        }

        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        return $extension === '' ? null : $extension;
    }
}
