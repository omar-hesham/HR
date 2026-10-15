<?php

namespace App\Actions\Documents;

use App\Domain\Documents\Disposition;
use App\Domain\Documents\ReviewReason;

/**
 * Per-file outcome of one upload batch (M2-P0-01: outcome per file).
 */
final class UploadResult
{
    /** @var list<array{index: int, source_id: int, disposition: Disposition, reason: ReviewReason|null}> */
    public array $items = [];

    public function __construct(public readonly string $batch) {}

    public function add(int $index, int $sourceId, Disposition $disposition, ?ReviewReason $reason = null): void
    {
        $this->items[] = ['index' => $index, 'source_id' => $sourceId, 'disposition' => $disposition, 'reason' => $reason];
    }

    public function count(Disposition $disposition): int
    {
        return count(array_filter($this->items, static fn (array $item): bool => $item['disposition'] === $disposition));
    }
}
