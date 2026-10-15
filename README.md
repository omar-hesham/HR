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
