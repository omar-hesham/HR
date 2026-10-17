<?php

namespace App\Services\Ai;

use Illuminate\Validation\ValidationException;

final class AiSettingsValidator
{
    /**
     * @param array<string,mixed> $changes
     * @param array<string,mixed> $current */
    public function validate(array $changes, array $current): void
    {
        $v = [...$current, ...$changes];
        $ranges = ['ai.monthly_cap_egp' => [0, 1000000], 'ai.breaker_threshold' => [1, 100], 'ai.recovery_seconds' => [60, 3600], 'ai.chars_per_token' => [.1, 4], 'ai.max_output_tokens' => [16, 32768], 'ai.input_egp_per_1k' => [.0001, 10000], 'ai.output_egp_per_1k' => [.0001, 10000], 'scoring.strong' => [0, 100], 'scoring.review' => [0, 100], 'scoring.confidence' => [.8, 1]];
        foreach ($ranges as $key => [$min,$max]) {
            if (! is_numeric($v[$key]) || $v[$key] < $min || $v[$key] > $max || ! is_finite((float) $v[$key])) {
                throw ValidationException::withMessages([$key => __('ai.invalid_setting')]);
            }
        }
        if ($v['scoring.review'] >= $v['scoring.strong']) {
            throw ValidationException::withMessages(['scoring.review' => __('ai.invalid_setting')]);
        }
        foreach (['ai.legal_approval_ref', 'ai.military_legal_ref', 'ai.minimum_age_legal_ref'] as $key) {
            if (! is_string($v[$key]) || mb_strlen($v[$key]) > 128) {
                throw ValidationException::withMessages([$key => __('ai.invalid_setting')]);
            }
        }
        if (! is_array($v['scoring.education_levels']) || $v['scoring.education_levels'] === [] || ! is_array($v['scoring.synonyms'])) {
            throw ValidationException::withMessages(['scoring.synonyms' => __('ai.invalid_setting')]);
        }
        foreach ($v['scoring.synonyms'] as $canonical => $aliases) {
            if (! is_string($canonical) || ! is_array($aliases) || ! array_is_list($aliases) || array_filter($aliases, fn ($a) => ! is_string($a) || trim($a) === '')) {
                throw ValidationException::withMessages(['scoring.synonyms' => __('ai.invalid_setting')]);
            }
        }
    }
}
