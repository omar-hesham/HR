<?php

namespace App\Domain\Scoring;

use App\Domain\Criteria\CriterionType;
use App\Models\Score;

final class ExplanationTemplate
{
    /**
     * @return array<string,string> */
    public function claims(Score $score): array
    {
        $claims = ['version' => __('ai.explanation.version', ['version' => $score->criteriaVersion->version], 'ar')];
        $missing = [];
        $strongest = [];
        foreach ($score->criterionResults()->with('criterion')->orderBy('criterion_id')->get() as $result) {
            $items = json_decode($result->evidence_enc ?? '[]', true, 512, JSON_THROW_ON_ERROR);
            $quotes = implode(' | ', array_column($items, 'quote'));
            $claims['criterion_'.$result->criterion_id] = __('ai.explanation.criterion', ['label' => $result->criterion->label_ar, 'outcome' => __('ai.outcomes.'.$result->result, [], 'ar'), 'evidence' => $quotes === '' ? __('ai.explanation.no_evidence', [], 'ar') : $quotes], 'ar');
            if ($result->criterion->type === CriterionType::MustHave && $result->result !== 'met') {
                $missing[] = $result->criterion->label_ar;
            }
            if ($result->result === 'met' && $quotes !== '') {
                $strongest[] = $quotes;
            }
        }
        $claims['missing_mandatory'] = __('ai.explanation.missing', ['labels' => $missing === [] ? __('ai.explanation.none', [], 'ar') : implode(' / ', $missing)], 'ar');
        $claims['strongest'] = __('ai.explanation.strongest', ['quotes' => $strongest === [] ? __('ai.explanation.no_evidence', [], 'ar') : implode(' | ', array_slice($strongest, 0, 3))], 'ar');
        $claims['result'] = $score->score === null ? __('ai.states.configuration_review', [], 'ar') : __('ai.explanation.result', ['score' => $score->score, 'coverage' => $score->coverage, 'band' => __('ai.bands.'.$score->band, [], 'ar')], 'ar');

        return $claims;
    }

    public function render(Score $score): string
    {
        return implode("\n", $this->claims($score));
    }
}
