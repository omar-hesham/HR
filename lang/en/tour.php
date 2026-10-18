<?php

return [
    'menu' => 'Guided tour',
    'next' => 'Next',
    'previous' => 'Previous',
    'skip' => 'Skip tour',
    'finish' => 'Finish tour',
    'counter' => ':current of :total',
    'save_failed' => 'We could not save your tour progress. Please try again.',
    'demo_warning' => 'Demo version — all data is synthetic. Do not upload any real CVs or personal data.',
    'steps' => [
        'welcome' => [
            'title' => 'Welcome to your review workspace',
            'body' => 'This short tour walks you through reviewing applications. Use Next and Previous, or the arrow keys, and press Esc to skip.',
        ],
        'screening' => [
            'title' => 'Start with Screening',
            'body' => 'The Screening group brings together applications, uploads, review items and reports. Available tools depend on your permissions.',
        ],
        'top_three' => [
            'title' => 'Top 3 candidates per job',
            'body' => 'The model extracts facts with evidence, then rules rank candidates against approved criteria. Review evidence and missing requirements; these are suggestions for your decision.',
        ],
        'ranking' => [
            'title' => 'Read the ranking and bands',
            'body' => 'Applications are ranked within each job using its current approved criteria. Strong match, needs review and weak match bands support your review; they are not hiring decisions.',
        ],
        'exceptions' => [
            'title' => 'Keep unscored applications in view',
            'body' => 'Unscored applications and exceptions stay in these pinned sections. Check the reason and resolve the issue so no application is overlooked.',
        ],
        'upload' => [
            'title' => 'Upload CVs',
            'body' => 'Select the job, then upload one or more supported files. On this public demo, use synthetic CVs only and never upload real personal data.',
        ],
        'needs_review' => [
            'title' => 'Follow up on Needs review',
            'body' => 'Open Needs review to inspect unreadable files and other intake problems. Review the reason before resolving or retrying an item.',
        ],
        'evidence' => [
            'title' => 'Open an application and check the evidence',
            'body' => 'After the tour, open an application to see each criterion, its outcome and the supporting evidence. Not mentioned is different from not met.',
        ],
        'export' => [
            'title' => 'Export a job',
            'body' => 'Authorized users can export a job for review. Job scope and name-blind settings apply, and broad exports require password confirmation.',
        ],
        'weekly' => [
            'title' => 'Weekly summary and performance indicators',
            'body' => 'Track intake, review progress and exceptions in the weekly summary. Record screening time manually during the pilot to compare performance.',
        ],
        'user_menu' => [
            'title' => 'Your language and guided tour',
            'body' => 'Open the user menu to switch between Arabic and English. Choose Guided tour here whenever you want to replay these steps.',
        ],
        'finish' => [
            'title' => 'The decision is always human',
            'body' => 'Scores and evidence help you review; nothing is rejected automatically. An authorized human makes and records every final decision.',
        ],
    ],
];
