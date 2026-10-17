<?php

return [
    'egp' => 'EGP', 'alert_subject' => 'Evaluation service temporarily suspended', 'alert_body' => 'The :driver evaluation service is suspended. Intake remains active. Check the AI operations page.',
    'manage_permission' => 'Manage AI pipeline and legal approvals',
    'classification_suggestion' => 'Suggested role: :classification; human link review required',
    'classifications' => ['cv' => 'CV', 'supporting_document' => 'Supporting document', 'uncertain' => 'Uncertain', 'other' => 'Other'],
    'title' => 'AI operations', 'pipeline' => 'Pipeline', 'driver' => 'Driver', 'breaker' => 'Circuit breaker', 'consumption' => 'Monthly cloud consumption / cap', 'waiting' => 'Waiting / review', 'alerts' => 'Operator alerts', 'recent_errors' => 'Recent errors', 'purpose' => 'Purpose', 'error' => 'Safe error code', 'request' => 'Request ID', 'time' => 'Time', 'on' => 'Enabled', 'off' => 'Disabled',
    'suspended' => 'Evaluation service temporarily suspended',
    'estimate_notice' => 'Cost rates and token reservations are inferred estimates pending an approved provider price table.',
    'invalid_setting' => 'Invalid AI or scoring setting.',
    'states' => [
        'awaiting_service' => 'Awaiting the evaluation service', 'awaiting_budget' => 'Awaiting budget/review', 'configuration_review' => 'Criteria configuration requires review',
        'queued' => 'Awaiting the evaluation service', 'waiting' => 'Awaiting the evaluation service', 'review' => 'Needs review', 'failed' => 'Evaluation needs review', 'deleted' => 'Deleted item',
        'classification_review' => 'Classification suggestion requires human link review',
        'Timeout' => 'Evaluation timed out; awaiting retry/review', 'AuthenticationFailed' => 'Provider authentication needs operator review', 'RateLimited' => 'Provider rate limited; awaiting retry/review',
        'BudgetExceeded' => 'Awaiting budget/review', 'InvalidSchema' => 'Evaluation contract requires review', 'LowConfidence' => 'Low confidence; needs review', 'MaskingFailed' => 'Identity/masking requires local review', 'ProviderUnavailable' => 'Awaiting the evaluation service',
        'value_not_supported' => 'Value is not supported by explicit evidence; needs review',
        'quote_not_found' => 'Evidence quote not found; needs review', 'quote_ambiguous' => 'Evidence quote is ambiguous; needs review',
    ],
    'bands' => ['strong_match' => 'Strong match', 'needs_review' => 'Needs review', 'weak_match' => 'Weak match', 'configuration_review' => 'Configuration review'],
    'breakers' => ['closed' => 'Closed', 'open' => 'Open', 'half_open' => 'One recovery trial'],
    'purposes' => ['extract' => 'Structured extraction', 'classify' => 'Ambiguous intake', 'explain' => 'Internal explanation', 'health' => 'Health probe'],
    'outcomes' => ['met' => 'Met', 'not_met' => 'Not met', 'not_mentioned' => 'Not mentioned', 'unclear' => 'Unclear'],
    'explanation' => ['version' => 'Criteria version :version.', 'criterion' => ':label: :outcome. Evidence: :evidence', 'no_evidence' => 'No verified evidence', 'none' => 'None', 'missing' => 'Missing or unmet must-haves: :labels.', 'strongest' => 'Strongest verified evidence: :quotes', 'result' => 'Deterministic score :score; evidence coverage :coverage%; band: :band. A human decides.'],
    'settings' => [
        'title' => 'AI and scoring controls', 'enabled' => 'Enable evaluation pipeline', 'ai_monthly_cap_egp' => 'Monthly cloud cap (EGP)', 'ai_breaker_threshold' => 'Consecutive provider failures', 'ai_recovery_seconds' => 'Recovery probe interval (seconds)',
        'ai_chars_per_token' => 'Conservative characters per token', 'ai_max_output_tokens' => 'Maximum output tokens', 'ai_input_egp_per_1k' => 'Estimated input cost (EGP/1000 tokens)', 'ai_output_egp_per_1k' => 'Estimated output cost (EGP/1000 tokens)',
        'ai_legal_approval_ref' => 'Written cloud approval reference', 'ai_military_legal_ref' => 'Military-status legal approval reference', 'ai_minimum_age_legal_ref' => 'Legal-minimum check approval reference', 'military_enabled' => 'Allow legally approved military status',
        'scoring_strong' => 'Strong band threshold', 'scoring_review' => 'Review band threshold', 'scoring_confidence' => 'Minimum confirmed confidence', 'synonyms' => 'Approved Arabic/English synonym dictionary (JSON)',
    ],
];
