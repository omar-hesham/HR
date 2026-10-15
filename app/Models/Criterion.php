<?php

namespace App\Models;

use App\Domain\Criteria\CriteriaVersionStatus;
use App\Domain\Criteria\CriterionData;
use App\Domain\Criteria\CriterionField;
use App\Domain\Criteria\CriterionOperator;
use App\Domain\Criteria\CriterionType;
use App\Models\Exceptions\ImmutableRecordException;
use Database\Factories\CriterionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $criteria_version_id
 * @property CriterionType $type
 * @property string $field
 * @property CriterionOperator $operator
 * @property array<string, mixed>|null $target_json
 * @property int|null $weight
 * @property string $label_ar
 * @property string $label_en
 * @property string $justification
 * @property string|null $legal_basis_note
 * @property int $sort
 * @property-read CriteriaVersion $version
 */
class Criterion extends Model
{
    /** @use HasFactory<CriterionFactory> */
    use HasFactory;

    protected $table = 'criteria';

    protected $fillable = [
        'criteria_version_id',
        'type',
        'field',
        'operator',
        'target_json',
        'weight',
        'label_ar',
        'label_en',
        'justification',
        'legal_basis_note',
        'sort',
    ];

    protected function casts(): array
    {
        return [
            'type' => CriterionType::class,
            'operator' => CriterionOperator::class,
            'target_json' => 'array',
            'weight' => 'integer',
        ];
    }

    /**
     * Criteria of a non-draft version can never change (M4-P0-02).
     */
    protected static function booted(): void
    {
        $guard = function (Criterion $criterion): void {
            $versionId = $criterion->getOriginal('criteria_version_id') ?? $criterion->criteria_version_id;
            $status = CriteriaVersion::query()->whereKey($versionId)->toBase()->value('status');

            if ($status !== null && $status !== CriteriaVersionStatus::Draft->value) {
                throw ImmutableRecordException::forModel($criterion);
            }
        };

        static::creating($guard);
        static::updating($guard);
        static::deleting($guard);
    }

    /**
     * @return BelongsTo<CriteriaVersion, $this>
     */
    public function version(): BelongsTo
    {
        return $this->belongsTo(CriteriaVersion::class, 'criteria_version_id');
    }

    public function fieldEnum(): ?CriterionField
    {
        return CriterionField::tryFrom($this->field);
    }

    public function label(): string
    {
        return app()->getLocale() === 'ar' ? $this->label_ar : $this->label_en;
    }

    public function toData(): CriterionData
    {
        return CriterionData::fromArray([
            'type' => $this->type->value,
            'field' => $this->field,
            'operator' => $this->operator->value,
            'target' => $this->target_json ?? [],
            'weight' => $this->weight,
            'label_ar' => $this->label_ar,
            'label_en' => $this->label_en,
            'justification' => $this->justification,
            'legal_basis_note' => $this->legal_basis_note,
        ]);
    }
}
