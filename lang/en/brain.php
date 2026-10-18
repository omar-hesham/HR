<?php

return [
    'top_title' => 'Top 3 candidates per job',
    'suggestion' => 'Suggested for review — the decision is yours. AI extracts evidence; approved rules determine the ranking. No status changes or automatic rejection.',
    'no_quotes' => 'No verified supporting quotes available.',
    'missing' => 'Missing or unresolved must-haves', 'none_missing' => 'All must-haves are supported.',
    'detail' => 'Review details and evidence', 'no_ranked' => 'No current-version ranked applications yet.',
    'fixture_mailbox' => 'Synthetic AI demo mailbox',
    'local_only' => 'demo_local_only: this command runs only in APP_ENV=local.',
    'actor_required' => 'demo_actor_required: select an existing usable actor with ai.pipeline.manage. Grant this through authorized user administration first.',
    'queued' => 'Raw synthetic documents imported and consent recorded. Normal extraction, AI and scoring work is queued.',
    'summary_help' => 'Snapshot only; pending work is not completed. Use --work to drain normal queues, or run workers and then --summary. Failure counts are error records, not distinct candidates.',
    'command_description' => 'Import raw synthetic documents, record consent and queue AI extraction/scoring (local only)',
    'failure_records' => 'Failure/deferred records', 'pending_documents' => 'Documents awaiting text extraction',
    'metric' => 'Metric', 'count' => 'Count', 'documents' => 'Documents', 'ai_runs' => 'AI runs',
    'succeeded' => 'Successful AI runs', 'scores' => 'Scores', 'pending' => 'Pending AI work',
    'rescore_options' => 'Rescore cannot be combined with --work or --with-mail; it runs only deterministic evaluation.',
    'rescored' => 'Rescore completed: :created new score rows; :skipped applications skipped.',
    'rescore_help' => 'Stored extractions reused; score history preserved. No AI work was dispatched. Counts by band refer to current scores.',
    'strong_match' => 'Current strong matches', 'needs_review' => 'Current needs review',
    'weak_match' => 'Current weak matches', 'configuration_review' => 'Current configuration reviews',
];
