<?php

namespace App\Actions\Criteria;

use App\Domain\Criteria\CriteriaValidator;
use App\Domain\Criteria\CriterionData;
use App\Models\CriteriaVersion;
use App\Models\Criterion;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Create or update one criterion of a draft version, after domain validation
 * (weights, operators, targets, protected attributes, location).
 */
final class SaveCriterion
{
    public function __construct(private readonly CriteriaValidator $validator) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function handle(CriteriaVersion $version, array $input, User $actor, ?Criterion $criterion = null): Criterion
    {
        Gate::forUser($actor)->authorize('update', $version);

        $data = CriterionData::fromArray($input);
        $result = $this->validator->validateCriterion($data);

        if (! $result->passes()) {
            throw new CriteriaValidationException($result);
        }

        $attributes = [
            'type' => $data->type,
            'field' => $data->field,
            'operator' => $data->operator,
            'target_json' => $data->target,
            'weight' => $data->weight,
            'label_ar' => $data->labelAr,
            'label_en' => $data->labelEn,
            'justification' => $data->justification,
            'legal_basis_note' => $data->legalBasisNote === '' ? null : $data->legalBasisNote,
            'sort' => (int) ($input['sort'] ?? ($criterion !== null ? $criterion->sort : $version->criteria()->count())),
        ];

        if ($criterion === null) {
            return Criterion::query()->create([...$attributes, 'criteria_version_id' => $version->id]);
        }

        $criterion->fill($attributes)->save();

        return $criterion;
    }

    public function delete(Criterion $criterion, User $actor): void
    {
        Gate::forUser($actor)->authorize('update', $criterion->version);

        $criterion->delete();
    }
}
