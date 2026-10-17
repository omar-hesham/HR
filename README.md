# Recruitment Screening Tool — Phase 1

Arabic-first (RTL) web tool that receives CVs, extracts their text, and (from Milestone 3)
scores them against each job's written criteria. **Humans make every decision; nothing is
rejected automatically.** Single-tenant, hosted in Egypt, Laravel 13 + Filament v5.

Status: **Milestone 1** — foundation, accounts/MFA/permissions, jobs & versioned criteria,
manual CV upload, encrypted storage, PDF/DOCX extraction, text-quality gate, "Needs review" list,
access log, settings. See `docs/PLAN.md` and `docs/DECISIONS.md`.

> Only synthetic data belongs in this repository and in development (spec D11 / PRIV-P0-05).
> Never put a real CV, name, phone or email here or into any cloud coding tool.

## Requirements

| Tool | Version | Notes |
|---|---|---|
| PHP | 8.4 | extensions: `intl`, `mbstring`, `fileinfo`, `zip`, `gd`, `sodium`, `pdo_sqlite` (local), `pdo_mysql` (server) |
| Composer | 2.8+ | |
| pdftotext | poppler-utils (server) / xpdf (Windows OK) | PDF extraction; tests that need it skip with a message if missing |
| LibreOffice | optional | only for legacy `.doc`, behind `DOC_CONVERSION_ENABLED` |
| Database | SQLite (local/tests), MySQL 8.4 LTS (production) | `utf8mb4` / `utf8mb4_0900_ai_ci` |

No Node.js is needed at runtime; Filament ships its compiled assets.

## Local setup on Windows

On Omar's PC PHP 8.4 lives in `I:\factory\tools\php84` (official build) next to `I:\factory\tools\composer.phar`.
From `I:\factory\recruit-app` in PowerShell:

```powershell
$env:Path = "I:\factory\tools\php84;" + $env:Path
php ..\tools\composer.phar install
copy .env.example .env
php artisan key:generate
php artisan recruit:key
```

Then edit `.env` (local only):

- `SEED_USER_PASSWORD=` a local password for the six synthetic demo accounts
- `PDFTOTEXT_PATH="C:/Program Files/Git/mingw64/bin/pdftotext.exe"` (Git for Windows ships xpdf's `pdftotext`)

```powershell
php artisan migrate:fresh --seed
php artisan serve
php artisan queue:work --queue=extraction,default   # second terminal: runs text extraction
```

Open <http://localhost:8000/admin> and sign in as `owner@example.test` (or `manager@`, `recruiter@`,
`coordinator@`, `management@`, `support@example.test`) with `SEED_USER_PASSWORD`.
The first sign-in forces TOTP set-up: scan the QR code with any authenticator app and keep the recovery codes.

Lost authenticator (logged procedure, M9-P0-04):

```powershell
php artisan recruit:mfa-reset owner@example.test --reason="Identity verified by phone with the owner"
```

## Quality gates

```powershell
php ..\tools\composer.phar lint    # vendor/bin/pint --test
php ..\tools\composer.phar stan    # vendor/bin/phpstan analyse (Larastan, level 6, no baseline)
php ..\tools\composer.phar test    # php artisan test (Pest)
php ..\tools\composer.phar gates   # all three
```

Synthetic CV fixtures live in `tests/Fixtures/cvs/` with expected outcomes in `manifest.json`.
Regenerate them with `php ..\tools\composer.phar fixtures` (needs the dev dependencies).

## Layout

```text
app/Domain/          pure rules: text normalization, quality check, criteria validation, statuses, access scope
app/Actions/         use cases called by the UI: upload, criteria drafts/approval, user access, review
app/Infrastructure/  encrypted storage, pdftotext/PHPWord/LibreOffice extraction
app/Jobs/            queued work (ExtractDocumentText)
app/Filament/        thin UI (resources/pages call actions; no business rules)
app/Policies/        server-side permission matrix (spec §4)
lang/ar, lang/en     every user-facing string
docs/                PLAN.md, DECISIONS.md
```

## Security notes

- CV files and extracted text are encrypted with `FILE_ENCRYPTION_KEY` (AES-256-GCM), separate from `APP_KEY`.
  Back up both keys separately from the data. Never rotate `FILE_ENCRYPTION_KEY` without a re-encryption plan.
- Files are stored under `storage/app/private`, never in `public/`; the only way to read one is the
  authorized, logged `/documents/{uuid}/download` route.
- Secrets live only in `.env` (not committed). Settings in the database never hold secrets.


## Milestone 2 — local mail intake

Use a `fake` mailbox with `host` pointing to a folder of synthetic `.eml` files and an explicit `start_at`.
New mailboxes default to the creation time, so older messages are not imported automatically.
To regenerate the 13 synthetic messages: `php tests/Fixtures/eml/generate.php`.

The scheduler dispatches polls every five minutes and a silence check hourly. Local commands:

```powershell
$env:PATH = "I:\factory\tools\php84;" + $env:PATH
php artisan recruit:poll-mailboxes
php artisan queue:work --queue=intake,extraction --timeout=300 --tries=3
php artisan schedule:work
```

Database queue `retry_after` defaults to 600 seconds, longer than the 290-second poll timeout.
Use separate workers for intake and extraction when enabling production scheduling.
The read-only health page is `/admin/intake-health`, authorized by `ops.health.view`.

`MALWARE_SCANNER=null` is allowed only in local/testing. Production requires `clamav` and a configured
socket. A scanner failure leaves the original and pointer recoverable; infected bytes remain encrypted
in quarantine and never enter extraction. Actual clamd integration still requires an authorized server test.

Notices use settings `privacy.notice_text_ar/en` (translation fallback) and the configured resend window.
`MAIL_SEND_TRANSPORT=array` is the safe local default. Approved SMTP requires the dedicated `notice`
mailer and `MAIL_SEND_HOST/PORT/SCHEME/USERNAME/PASSWORD`, separate from intake credentials.
Do not use the log mailer for candidate data. Pending outbox rows are redispatched by the poll command.
Uncertain/sending notices require manual delivery reconciliation; they are shown in health and never
resent automatically. Test failures and queue payloads contain safe codes and identifiers only.

Mailbox secrets can use the encrypted, serialization-hidden `credentials` array (username/password,
tenant_id/client_id/client_secret/refresh_token), or a non-secret `credential_ref` into
`recruitment.intake.credentials`; the default config set uses the `MAILBOX_*` environment values.
Live IMAP/OAuth/SMTP permissions, TLS and limits have not been accepted by offline tests.

## Milestone 3 — AI extraction and deterministic scoring

The pipeline ships **disabled**. `AI_DRIVER=fake|local|cloud` selects one adapter; failures never switch
providers. Fake responses are explicitly loaded with `FakeAiDriver::push()` for synthetic local tests.
The real adapter uses Laravel AI SDK 1.2.3's `openai-compatible` connection. Set local service URL/model
only on the authorized Egyptian staging server. Loopback HTTP is supported; other private endpoints
and cloud require TLS. Redirects and SDK-internal retries are disabled (D-139).

The AI operations page is `/admin/ai-operations`. `ops.health.view` can inspect state, consumption and
safe errors without candidate text. `ai.pipeline.manage` is a separate explicit grant available to an
owner through the existing user permissions editor; no role receives it by default. Ordinary business
settings access cannot change AI controls. Pipeline activation, the written legal reference, the military
exception and scoring defaults are audited through `SettingsRepository`.

Cloud also requires successful deterministic masking and scoped, verified consent. `ConsentRepository`
records a candidate **or** intake-message consent with reference, verifier and time; revoke it there when
needed. Its domain API is ready for the M4 review UI. Ambiguous intake waits for consent even locally.
Names and contacts always come from PHP. City/governorate keys are fixed to null; the model never
supplies displayed identity, a score, a human decision or candidate-facing messages.

With the default 600-second local timeout, run **one** AI worker and separate deterministic/intake workers:

```powershell
$env:PATH = "I:\factory\tools\php84;" + $env:PATH
php artisan queue:work ai --queue=ai --timeout=660 --tries=3 --sleep=3
php artisan queue:work --queue=scoring,default --timeout=120 --tries=3
php artisan queue:work --queue=intake,extraction --timeout=300 --tries=3
php artisan schedule:work
```

The `ai` connection reserves jobs for at least 900 seconds. The database connector guards the `ai`
queue **before pop**, including an accidental default-connection consumer. Intake/extraction retain their
existing retry settings. Provider waits use durable identifier-only work records; actual dispatch attempts
and monetary reservations are persisted together. Recovery probes run outside `ai` every minute with a
10-second ping. Health recovery permits a single leased half-open work trial; only its success resumes
processing. Operator alerts persist in the activity log/banner and use the existing approved
`HEALTH_ALERT_RECIPIENT` / notice transport when configured.

Cost rates are `[INFERRED]` settings, not a provider price table. The monthly cloud cap defaults to
300 EGP. Unknown failures retain their full reservation; reconciliation is bounded by the reservation
and flags any overrun for review. Budget exhaustion is a visible wait, not a provider outage.

Approved criteria snapshot thresholds, dictionary and education ordering. Existing approved versions
without the full M3 snapshot need a newly approved version; they are never edited retrospectively.
The independent recovery command `php artisan recruit:dispatch-ai` rechecks waiting work and lost
score dispatches. A criteria change or field correction creates a new evaluation using existing facts;
true duplicates with a valid current score enqueue no extraction or rescoring. Correction and override
actions preserve actor/reason/history; their UI remains M4. Explanations are Arabic in either UI locale.
A deterministic explanation exists immediately; the model can only order verified stored claim IDs.

Synthetic recorded contracts and masking data live in `tests/Fixtures/ai/`; regenerate with
`php tests/Fixtures/ai/generate.php`. No test makes a live AI request. The current SDK and schema APIs
were checked against the [official AI SDK documentation](https://laravel.com/docs/13.x/ai-sdk) and
[Opis validator documentation](https://opis.io/json-schema/2.x/php-validator.html), then against installed
source and mocked wire requests. Endpoint/model performance, TLS/egress, real provider billing, legal
approval and sample calibration still require the separately authorized staging/acceptance work.

When applying the reviewed M3 upgrade, run normal migrations and re-seed only the permission registry
(`php artisan migrate`, `php artisan db:seed --class=RolesAndPermissionsSeeder`); this registers the new
explicit permission without granting it to a role. The destructive fresh/seed verification above was run
only against a newly created isolated SQLite file. See `docs/M3-VERIFICATION.md` for exact gate results.
