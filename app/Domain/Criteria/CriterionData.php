<?php

namespace App\Domain\Criteria;

/**
 * Plain input for one criterion, independent of Eloquent and Filament.
 */
final readonly class CriterionData
{
    /**
     * @param  array<string, mixed>  $target
     */
    public function __construct(
        public string $type,
        public string $field,
        public string $operator,
        public array $target,
        public ?int $weight,
        public string $labelAr,
        public string $labelEn,
        public string $justification,
        public ?string $legalBasisNote,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $target = $data['target'] ?? [];
        $weight = $data['weight'] ?? null;

        return new self(
            type: (string) ($data['type'] ?? ''),
            field: (string) ($data['field'] ?? ''),
            operator: (string) ($data['operator'] ?? ''),
            target: is_array($target) ? $target : [],
            weight: $weight === null || $weight === '' ? null : (is_numeric($weight) ? (int) $weight : -1),
            labelAr: trim((string) ($data['label_ar'] ?? '')),
            labelEn: trim((string) ($data['label_en'] ?? '')),
            justification: trim((string) ($data['justification'] ?? '')),
            legalBasisNote: isset($data['legal_basis_note']) ? trim((string) $data['legal_basis_note']) : null,
        );
    }
}
