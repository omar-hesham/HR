<?php

/*
| Recruitment-domain configuration. Values here are defaults only: anything the
| factory approves (retention, thresholds, limits, language) is editable in the
| settings table and read through App\Services\Settings\SettingsRepository.
| Secrets never live here; they come from .env.
*/

return [

    'display_timezone' => env('APP_DISPLAY_TIMEZONE', 'Africa/Cairo'),

    'supported_locales' => ['ar', 'en'],

    // Local/testing demo accounts only (DemoUsersSeeder refuses to run in production).
    'seed_user_password' => env('SEED_USER_PASSWORD', ''),

    /*
    | Defaults for the settings screen (key => default value).
    */
    'settings_defaults' => [
        ...require __DIR__.'/ai-defaults.php',
        'ui.default_locale' => env('APP_LOCALE', 'ar'),

        // Applicant track (PRIV-P0-03). Placeholders until the factory approves (Q05).
        'retention.applicant_days' => (int) env('RETENTION_DAYS', 365),
        'retention.idle_days' => (int) env('RETENTION_IDLE_DAYS', 180),
        'retention.hard_max_days' => (int) env('RETENTION_HARD_MAX_DAYS', 730),
        'retention.owner_notice_days' => (int) env('RETENTION_OWNER_NOTICE_DAYS', 14),
        // Hired track: days after hand-over to the employee file before the in-tool copy is removed.
        'retention.hired_handover_days' => (int) env('RETENTION_HIRED_HANDOVER_DAYS', 30),

        // M2-P0-04 initial thresholds.
        'quality.min_letter_ratio' => (float) env('TEXT_MIN_LETTER_RATIO', 0.60),
        'quality.min_wordlist_ratio' => (float) env('TEXT_MIN_WORDLIST_RATIO', 0.40),
        'quality.max_reversed_ratio' => (float) env('TEXT_MAX_REVERSED_RATIO', 0.20),
        'quality.min_letters' => (int) env('TEXT_MIN_LETTERS', 40),

        // M2-P0-02 initial design limits.
        'upload.max_file_mb' => (int) env('UPLOAD_MAX_FILE_MB', 10),
        'upload.max_batch_files' => (int) env('UPLOAD_MAX_BATCH_FILES', 50),
        'upload.max_batches_per_hour' => (int) env('UPLOAD_MAX_BATCHES_PER_HOUR', 10),

        // Kickoff §5.6 default for new jobs; per-user and per-job flags override to "on".
        'privacy.name_blind_default' => false,
        // M1-P0-05: ID card images are not retained unless the factory approves.
        'privacy.keep_id_card_attachments' => (bool) env('KEEP_ID_CARD_ATTACHMENTS', false),

        // Notice copy is approved by the factory; defaults are seeded from lang (D-114)
        // and SendPrivacyNotice falls back to lang when these are empty.
        'privacy.notice_version' => env('PRIVACY_NOTICE_VERSION') ?: '1',
        'privacy.notice_text_ar' => (string) env('PRIVACY_NOTICE_TEXT_AR', ''),
        'privacy.notice_text_en' => (string) env('PRIVACY_NOTICE_TEXT_EN', ''),

        // Milestone 2 intake settings.
        // "No mail received" alert threshold in hours (per-mailbox column overrides).
        'intake.silence_alert_hours' => (int) env('INTAKE_SILENCE_ALERT_HOURS', 48),
        // Max one privacy notice per sender within this window (days).
        'intake.privacy_notice_resend_days' => (int) env('INTAKE_PRIVACY_NOTICE_RESEND_DAYS', 30),
    ],

    'extraction' => [
        'pdftotext_path' => env('PDFTOTEXT_PATH', 'pdftotext'),
        'pdftotext_timeout' => (int) env('PDFTOTEXT_TIMEOUT', 60),
        'doc_enabled' => (bool) env('DOC_CONVERSION_ENABLED', false),
        'libreoffice_path' => env('LIBREOFFICE_PATH', 'soffice'),
        'libreoffice_timeout' => (int) env('LIBREOFFICE_TIMEOUT', 60),
        // Bump when extractor behaviour changes; documents extracted with an older
        // version may be re-extracted, current ones are skipped (idempotency).
        'extractor_version' => '1',
        'allowed_extensions' => ['pdf', 'docx', 'doc'],
    ],

    'storage' => [
        // Separate from APP_KEY (SEC-P0-02). Generate with: php artisan recruit:key
        'file_encryption_key' => env('FILE_ENCRYPTION_KEY'),
        'disk' => 'private',
        'documents_prefix' => 'candidates',
        'tmp_prefix' => 'tmp',
        // Raw .eml files (encrypted) and malware quarantine (Milestone 2).
        'intake_prefix' => 'intake',
        'quarantine_prefix' => 'quarantine',
    ],

    // Pseudonymous sender identity for de-duplication / notice windows (D-115).
    'hash' => [
        'dedup_key' => env('DEDUP_HMAC_KEY'),
    ],

    'queues' => [
        'extraction' => 'extraction',
        'intake' => 'intake',
    ],

    /*
    | Mail intake (Milestone 2). Providers: m365 | google | imap | fake.
    | The fake driver reads .eml files from a folder (tests & local only).
    */
    'intake' => [
        'credentials' => [
            'default' => [
                'username' => env('MAILBOX_USERNAME'),
                'password' => env('MAILBOX_PASSWORD'),
                'tenant_id' => env('MAILBOX_TENANT_ID'),
                'client_id' => env('MAILBOX_CLIENT_ID'),
                'client_secret' => env('MAILBOX_CLIENT_SECRET'),
                'refresh_token' => env('MAILBOX_REFRESH_TOKEN'),
            ],
        ],
        'folder' => env('MAILBOX_FOLDER', 'INBOX'),
        'poll_minutes' => (int) env('INTAKE_POLL_MINUTES', 5),
        'max_messages_per_poll' => (int) env('INTAKE_MAX_MESSAGES_PER_POLL', 100),
        // Per-attachment cap before classification (mail has no upload form).
        'max_attachment_mb' => (int) env('INTAKE_MAX_ATTACHMENT_MB', 25),
        'fake_path' => env('MAILBOX_FAKE_PATH', storage_path('app/fake-mailbox')),
        'image_extensions' => ['png', 'jpg', 'jpeg', 'gif', 'webp', 'bmp', 'tiff'],
        // Filename terms (search-normalized) that keep an unknown file out of "other".
        'cv_filename_terms' => ['cv', 'resume', 'profile'],
        // OAuth2 token endpoints for the XOAUTH2 providers (M1-P0-04).
        'm365_token_url' => 'https://login.microsoftonline.com/%s/oauth2/v2.0/token',
        'google_token_url' => 'https://oauth2.googleapis.com/token',
        // Dedicated notice mailer (spec §5.3: separate dispatch credentials).
        'notice_mailer' => env('MAIL_SEND_TRANSPORT') ?: 'array',
        // Mailbox-silence alerts go here (spec §9.5 HEALTH_ALERT_RECIPIENT); empty disables mail alerts.
        'health_alert_recipient' => env('HEALTH_ALERT_RECIPIENT'),
    ],

    /*
    | Malware scanning (M2-P0-02). "null" disables scanning (local dev has no
    | clamd); production sets MALWARE_SCANNER=clamav and CLAMAV_SOCKET.
    */
    'malware' => [
        'driver' => env('MALWARE_SCANNER', 'null'),
        'clamav' => [
            'socket' => env('CLAMAV_SOCKET', 'unix:///var/run/clamav/clamd.ctl'),
            'timeout' => (int) env('CLAMAV_TIMEOUT', 30),
            // clamd INSTREAM chunk size.
            'chunk_size' => 4096,
        ],
    ],

    /*
    | Ordered qualification levels (lowest first). "gte" on education compares
    | positions in this list. Generic Egyptian levels, not factory-specific.
    */
    'qualification_levels' => [
        'none',
        'basic_education',
        'preparatory',
        'technical_diploma',
        'general_secondary',
        'technical_institute',
        'bachelor',
        'postgraduate_diploma',
        'master',
        'doctorate',
    ],

    /*
    | Kickoff §5.4 / SC-P0-04: names a criterion field may never use, and
    | unambiguous terms that may not appear in criterion labels or targets.
    | Location (Labour Law 14/2025) is included: kickoff §5.3.
    */
    'protected' => [
        'fields' => [
            'religion', 'faith', 'sect', 'gender', 'sex', 'marital_status', 'marital', 'married',
            'age', 'birth_date', 'date_of_birth', 'dob', 'photo', 'picture', 'image', 'disability',
            'health', 'medical', 'pregnancy', 'political_affiliation', 'politics', 'party',
            'union_membership', 'union', 'nationality', 'national_origin', 'origin', 'ethnicity',
            'race', 'tribe', 'family_name', 'governorate', 'city', 'address', 'district', 'area',
            'location', 'residence', 'place_of_birth', 'national_id',
        ],
        'terms' => [
            // English
            'religion', 'muslim', 'christian', 'gender', 'male only', 'female only', 'males only',
            'females only', 'married', 'single only', 'marital', 'pregnant', 'pregnancy', 'disabled', 'disability',
            'nationality', 'governorate', 'residence', 'resident of', 'lives in', 'age under',
            'age over', 'years old', 'photo', 'political', 'trade union',
            // Arabic, written in search-normalized form. Matched as whole tokens / token
            // sequences (never substrings), see ProtectedAttributeGuard.
            'الديانه', 'مسلم', 'مسيحي', 'ذكور فقط', 'اناث فقط', 'متزوج', 'اعزب', 'الحاله الاجتماعيه',
            'حامل', 'اعاقه', 'الجنسيه', 'محافظه', 'سكان', 'مقيم في', 'السن', 'العمر', 'صوره شخصيه',
            'حزب', 'نقابه',
        ],
        // Terms a "legal minimum age confirmed" criterion may mention (it is a yes/no check).
        'age_terms' => ['السن', 'العمر', 'age under', 'age over', 'years old'],
    ],
];
