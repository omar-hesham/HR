<?php

return [
    'ai.enabled' => (bool) env('AI_PIPELINE_ENABLED', false),
    'ai.monthly_cap_egp' => (float) env('AI_MONTHLY_CAP_EGP', 300),
    'ai.legal_approval_ref' => (string) env('AI_LEGAL_APPROVAL_REF', ''),
    'ai.breaker_threshold' => (int) env('AI_CIRCUIT_FAILURE_THRESHOLD', 5),
    'ai.recovery_seconds' => (int) env('AI_CIRCUIT_RECOVERY_SECONDS', 60),
    // INFERRED conservative estimates, NOT a verified provider price table.
    'ai.chars_per_token' => .5, 'ai.max_output_tokens' => 4096,
    'ai.input_egp_per_1k' => .1, 'ai.output_egp_per_1k' => .4,
    'ai.military_enabled' => false, 'ai.military_legal_ref' => '', 'ai.minimum_age_legal_ref' => '',
    'scoring.strong' => 70.0, 'scoring.review' => 40.0, 'scoring.confidence' => .80,
    'scoring.education_levels' => ['none', 'basic_education', 'preparatory', 'technical_diploma', 'general_secondary', 'technical_institute', 'bachelor', 'postgraduate_diploma', 'master', 'doctorate'],
    'scoring.synonyms' => (require __DIR__.'/ai-rules.php')['synonyms'],
];
