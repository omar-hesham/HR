# Decisions log — Milestone 1

Choices the spec v0.2 / kickoff do not make, each with a one-line reason. `D-xx` numbers are local to this repo.

## Packages added

| Package | Version | Why |
|---|---|---|
| `filament/filament` | ^5.0 (5.10.2) | Fixed decision (UI). |
| `spatie/laravel-permission` | ^8.3 | Fixed decision (roles). v8 is the current major for Laravel 12+. |
| `spatie/laravel-activitylog` | ^5.1 | Fixed decision (access log). v5 needs PHP 8.4 — matches our target. |
| `phpoffice/phpword` | ^1.4 | Fixed decision (DOCX). Also used to generate DOCX fixtures. |
| `spatie/pdf-to-text` | ^1.55 | Named in kickoff; thin wrapper around `pdftotext`. We call it with a timeout and explicit options. |
| `giggsey/libphonenumber-for-php` | ^9.0 | Named in spec §9.2; correct E.164 parsing/validation instead of hand-written regexes. |
| `pestphp/pest`, `pestphp/pest-plugin-laravel` (dev) | ^4 | Fixed decision (tests). |
| `larastan/larastan` (dev) | ^3.13 | Fixed decision (static analysis). |
| `mpdf/mpdf` (dev only) | ^8.3 | **Not named.** Needed to generate synthetic Arabic/English/password-protected/image-only PDF fixtures in pure PHP because LibreOffice is not installed on this PC. Dev-only; never loaded at runtime. |

Not installed: `laravel/ai` (Milestone 3), `webklex/php-imap` (Milestone 2), `opis/json-schema` (Milestone 3), `laravel/boost` (the Laravel 13 skeleton's `CLAUDE.md`/`AGENTS.md` ask agents to install it; not in our allowed list, so those two skeleton files were replaced with project notes).

## Environment

| # | Decision | Reason |
|---|---|---|
| D-01 | Local PHP is the official PHP 8.4.26 Windows NTS build in `I:\factory\tools\php84` (SHA-256 verified), with a fresh `composer.phar` 2.10.3 next to it. | Only Herd PHP 8.2/8.3 existed; the stack is fixed at PHP 8.4. Nothing system-wide was changed. |
| D-02 | Local `pdftotext` is xpdf 4.00 from Git-for-Windows (`/mingw64/bin`), not poppler. Production uses poppler-utils. The binary path is configurable (`PDFTOTEXT_PATH`). | That is what exists on this PC; extraction tests skip with a message if the binary is missing. |
| D-03 | **Verified on MySQL 8.4.11** (15/10/2026): official Windows zip (MD5-checked), disposable data dir, port 3407, strict `sql_mode`, server default `utf8mb4_0900_ai_ci`. `migrate:fresh --seed` clean; two `migrate` → `migrate:reset` cycles leave zero tables; full Pest suite 239 tests (238 passed, 1 skipped = LibreOffice) identical to SQLite. All 37 tables use `utf8mb4_0900_ai_ci`; the 11 hash/identifier columns are `ascii` / `ascii_bin`; Arabic stored as correct UTF-8 bytes. Fix found: the spatie activitylog v5 migration stub has no `down()` (reset left `activity_log` behind) — added, plus a test that every migration has a `down()`. To repeat: export `DB_CONNECTION=mysql DB_HOST DB_PORT DB_DATABASE DB_USERNAME DB_PASSWORD` for the process and run `php artisan migrate:fresh --seed` / `php artisan test` (phpunit.xml's SQLite defaults do not override process env). | Kickoff requires every migration to run on MySQL 8.4. Staging should still repeat this on the real server image. |

## Access and security

| # | Decision | Reason |
|---|---|---|
| D-10 | Roles: `owner` (system owner/approver), `hiring_manager`, `recruiter`, `coordinator`, `management` (read-only), `operator` (support). | Spec §4 personas + kickoff's operator/support role. |
| D-11 | "Restricted" cells in the §4 matrix are **not** granted to any role. They are separate permissions (e.g. `criteria.approve`, `documents.download`, `exports.create`, `access_log.view`) an owner can grant to a single user directly. Job scope still applies. | Spec: "Restricted denotes an explicit additional permission and an approved job scope; it does not imply default permission." |
| D-12 | Job scope = `recruitment_job_user` pivot. Owner has `applications.view_all`; everyone else sees only assigned jobs. Unassigned applications are visible to owner and recruiter (`applications.view_unassigned`). | Recruiter must handle "unknown job" items; hiring managers only their jobs. |
| D-13 | Read-only management sees only applications in `shortlisted` (and later) statuses for assigned jobs. | Matrix: "Approved shortlist". |
| D-14 | Coordinator gets `users.provision`: create accounts (no roles), activate/deactivate. Only `owner` (`users.manage`) assigns roles/permissions and job scopes. Nobody edits their own roles. | Prevents the coordinator from escalating into CV access (`M9-P0-01`). |
| D-15 | Operator/support account: no candidate data, must have `access_expires_at`; login refused after it passes. | Spec §4: "technical support account is separate and time-limited". |
| D-16 | MFA = Filament app (TOTP) authentication, `isRequired: true`, recovery codes on (8, hashed by Filament). Email codes not enabled. Secret stored with the `encrypted` cast. | `SEC-P0-01` prefers TOTP; email is not first choice. |
| D-17 | MFA reset = `php artisan recruit:mfa-reset {email} --reason=` run by Omar after out-of-band identity check; writes an activity-log entry and kills sessions. | `M9-P0-04` "logged administrative verification procedure". |
| D-18 | Sessions: database driver, `SESSION_LIFETIME=30` (idle timeout), `expire_on_close=false`, HttpOnly, SameSite=lax, `SESSION_SECURE_COOKIE=true` in production. | `M9-P0-04` / `SEC-P0-01`. Database driver lets us delete a user's sessions on revocation. |
| D-19 | When a role or permission is removed, `SyncUserRoles` logs actor/old/new and deletes the user's rows in `sessions`. Deactivation does the same. | `M9-P0-03`. |
| D-20 | Uploading CVs: `owner`, `recruiter`, `hiring_manager` (assigned jobs or no job). Not coordinator/management/operator. | Matrix does not say; upload is part of the recruiter's intake work; HM may receive paper CVs. |
| D-21 | Login rate limit = Filament's built-in (5 attempts/minute). Upload limited to 10 batches/hour/user. | `SEC-P0-04` initial values. |

## Data model

| # | Decision | Reason |
|---|---|---|
| D-30 | All enumerations are `string` columns backed by PHP enums (no MySQL `ENUM`). | Portable across SQLite/MySQL; adding a value needs no `ALTER`. |
| D-31 | `access_events` (spec §9.3) is implemented with `spatie/laravel-activitylog`'s `activity_log` table. Properties never hold CV text, names, or secrets — only ids, request id and reason codes. | Kickoff fixes activitylog for the access log. |
| D-32 | Review state (open reason, resolved by/at, note) lives on `document_sources`, one row per received file. | Rejected files have no document; every file must stay visible (`WF-P0-01`). |
| D-33 | `documents.sha256` is unique; an identical upload creates a new `document_sources` row with `disposition = duplicate_linked` and never a new extraction. | `M3-P0-01`, kickoff "link, do not re-process". |
| D-34 | Duplicate file + same candidate + same job (or both unassigned) → link to that application; a different job → new application; job is never inherited. Score-version check is added in Milestone 3. | `M3-P0-01` restricted to what exists in M1. |
| D-35 | Each uploaded CV creates a provisional candidate (no name/contacts yet). Contacts are extracted deterministically in Milestone 3. | Spec: missing contacts do not block a provisional candidate. |
| D-36 | Milestone 1 upload accepts CVs only. Supporting-document classification (`M1-P0-05`) arrives with mail intake in Milestone 2, where the classifier lives. | Keeps one classifier for both channels. |
| D-37 | Encryption: CV files and CV-derived text use a dedicated key `FILE_ENCRYPTION_KEY` with AES-256-GCM (Laravel `Encrypter`, authenticated). Notes/reasons use `APP_KEY` (`encrypted` cast). Names/contacts/search columns are plaintext by design (spec §9.3). | `SEC-P0-02`: separate keys, integrity verification. |
| D-38 | Files live on the `private` disk under `storage/app/private/candidates/{candidate_uuid}/documents/{document_uuid}.bin`; never under `public/`. Temp plaintext copies go to `storage/app/private/tmp/` and are deleted in `finally`. | `ARC-P0-04`. |
| D-39 | `applications.source` + `external_reference`, `document_sources.source` + `external_reference`, `intake_messages.source`. Enum: `email, upload, whatsapp, web_form, walk_in, platform`. | Kickoff §5.1. |
| D-40 | Retention tracks: `applications.retention_track` (`applicant` / `hired_handover`) + `handed_over_at`. Settings: `retention.applicant_days`, `retention.idle_days`, `retention.hard_max_days`, `retention.owner_notice_days`, `retention.hired_handover_days`. Values are placeholders until the factory approves (`Q05`). No deletion job in M1. | Kickoff §5.5, `PRIV-P0-03`. |
| D-41 | Name-blind: `users.name_blind_review`, `recruitment_jobs.name_blind_review`; effective if either is true (`NameBlindPolicy`). UI in Milestone 4. | Kickoff §5.6. |
| D-42 | `settings` table = key + `value_json` + `version` + `updated_by`; history via activity log (old/new). Secrets are never stored there. | Spec §9.3 + `M11-P0-03`. |
| D-43 | Notice outbox table `notice_outbox` created now (used in M2). | Spec §9.3 mentions an outbox for notices. |

## Criteria

| # | Decision | Reason |
|---|---|---|
| D-50 | Criteria fields come from a closed registry (`CriterionField` enum). Protected attributes are never in it; a separate `ProtectedAttributeGuard` gives a named error if one is submitted and scans labels/targets for protected terms (list in `config/recruitment.php`). | Kickoff §5.4, `SC-P0-04`, `M4-P0-03`. |
| D-51 | No governorate/city/address field. Replacement field `can_reach_plant_for_shift` (operator `is_explicitly_true`), answered by the candidate or recruiter, never inferred from an address. | Kickoff §5.3 (Labour Law 14/2025). |
| D-52 | `military_status` and `legal_minimum_age_confirmed` exist but require a non-empty legal basis note; age is only a yes/no legal-minimum check, never a number. | Spec §7.1 / kickoff §5.4 exception. |
| D-53 | Every criterion needs Arabic + English labels and a job-relevance justification. Disqualifiers additionally require a legal basis note. | `M4-P0-01`, `SC-P0-02`. |
| D-54 | Weights: `nice_to_have` needs an integer 1–100; `must_have`/`disqualifier` must have no weight (they are gates). A version cannot be approved without at least one weighted nice-to-have. | Formula `SC-P0-03` has no score otherwise; we block approval rather than approve a version that can never score. |
| D-55 | Conflict checks on save/approve: duplicate criteria; numeric ranges on the same field that cannot both hold (e.g. must-have `gte 5` and `lte 3`); a must-have and a disqualifier with the same field/operator/target. | `M4-P0-03`. |
| D-56 | One open draft per job. Editing an approved version = "new draft from version N" (criteria copied). Approved versions are immutable (model guard + policy). Approving sets `recruitment_jobs.current_criteria_version_id` and logs a `rescore_required` activity (rescoring is M3). | `M4-P0-02`. |
| D-57 | Drafts: owner and hiring manager (assigned jobs). Approval: owner; hiring manager only with the explicit `criteria.approve` permission. | §4 matrix "Draft; restricted approval". |
| D-58 | Qualification levels list (ordered) lives in `config/recruitment.php` and is overridable in settings later. | Spec: "approved list of levels"; not factory-specific data. |

## Text and documents

| # | Decision | Reason |
|---|---|---|
| D-60 | Pipeline: original → raw text (encrypted) → NFKC per base-character segment → whitespace cleanup → offset map (normalized index → raw index runs, encrypted) → quality check. Arabic lines are never auto-reversed. | `TXT-P0-03`. |
| D-61 | Search normalization (candidates only): NFKC, lowercase, أ/إ/آ/ٱ→ا, ى→ي, ة→ه, strip tashkeel (U+064B–U+065F, U+0670) and tatweel, ٠–٩/۰–۹→0–9, collapse spaces. | `TXT-P0-03`; never used as evidence. |
| D-62 | Quality metrics exactly as `M2-P0-04` (letter ratio ≥ 0.60, wordlist ratio ≥ 0.40, reversed ratio ≤ 0.20), plus `min_letters` = 40 below which the file is "image only / no text". Thresholds editable in settings. Overall `quality_score` = mean of the three margins, for display only; the decision uses the thresholds. | Spec values; min-letters needed to tell image-only PDFs apart. |
| D-63 | Wordlists: `resources/wordlists/{ar,en}.txt`, small synthetic lists of common CV vocabulary written for this project; matched after search normalization and Arabic clitic stripping (و، ب، ل، ال، بال، وال، لل، فال). | Spec asks for wordlists; calibration is Week-1 work. |
| D-64 | `pdftotext` runs without `-layout` (reading order), `-enc UTF-8`, 60 s timeout. Encrypted PDFs are detected by `/Encrypt` in the file and by `pdftotext` error output → `password_protected`. | Layout mode merges two columns line by line. |
| D-65 | DOC support behind `DOC_CONVERSION_ENABLED` (default `false`) + `LIBREOFFICE_PATH`; conversion uses an isolated temp user profile and a 60 s timeout. With the flag off, `.doc` goes to Needs review (`doc_conversion_disabled`). | `M2-P0-03`; LibreOffice not available locally. |
| D-66 | Upload limits: 10 MB per file, 50 files per batch (settings). Allowed: `pdf`, `docx`, `doc`. MIME via `finfo` **and** magic bytes (`%PDF-`, ZIP with `word/document.xml`, OLE `D0CF11E0`). | `M2-P0-02`. |
| D-67 | Malware scan is not in Milestone 1 (ClamAV integration comes with mail intake in M2). Files are never executed, macros never run, DOCX is parsed as XML only. | Kickoff lists malware scan under Milestone 2. |
| D-68 | Extraction job: queue `extraction`, `tries = 3`, `backoff = [30, 120]`, `timeout = 120`, `ShouldBeUnique` per document, `WithoutOverlapping` per document, exits early when a current extraction exists. Permanent errors (corrupt, protected, image-only) go to review without retry. | Spec §9.4 `ExtractDocument`. |
| D-69 | Egyptian phones: libphonenumber with region `EG`; result keeps the original. Only valid EG numbers become `+20…`; other international numbers keep their own country code; invalid/incomplete → `null` with reason, never guessed. | `TXT-P0-04`. |

## UI and language

| # | Decision | Reason |
|---|---|---|
| D-80 | Default locale `ar`, fallback `en`; `users.locale` per user; switch in the Filament user menu (GET route, CSRF-safe because it only changes the caller's own preference). | `TXT-P0-02`. |
| D-81 | Font: Noto Sans Arabic (SIL OFL 1.1) self-hosted from `public/fonts/noto-sans-arabic/` via Filament `LocalFontProvider`; no Google/Bunny CDN. | `TXT-P0-04`; licence is OFL (confirmed in the font package metadata). |
| D-82 | Display timezone `Africa/Cairo` through Filament's timezone setting; app/DB timezone `UTC`. Dates `d/m/Y`, date-times `d/m/Y H:i`. | `TXT-P0-04`. |
| D-83 | Numbers are formatted with Latin digits regardless of UI locale. | Kickoff "Western digits 0–9". |

| D-84 | Only `ApplyUserLocale` is a persistent (Livewire) panel middleware. Cookie/session middleware must never be persistent: found in manual QA that it double-decrypts the cookie on every Livewire update and signs the user out. Guarded by `tests/Feature/Auth/PanelMiddlewareTest.php`. | Bug found and fixed during Step 6. |
| D-85 | Livewire keeps a short-lived private temp copy of each uploaded file (`storage/app/private/livewire-tmp`, default disk, not public); the upload page deletes it right after `UploadDocuments` encrypts and stores the file. | Unavoidable for browser uploads; never on a public disk. |

## Findings from QA (Step 6)

- The synthetic Arabic PDFs (mpdf + DejaVu) produce **presentation-form glyphs** (repaired by NFKC: 300+ per file) and **a few unmapped glyphs** (private-use code points, e.g. medial kaf). They are counted as `unmapped_glyphs` in the quality report and never guessed. Real factory PDFs may behave differently; Week-1 calibration should look at this metric.
- Tests use `actingAs()`, which hides session bugs; a real-session regression test now exists (D-84).

## Design rules recorded for later milestones

| # | Rule | Milestone |
|---|---|---|
| D-90 | The LLM never writes the text of a rejection message to a candidate; any rejection text is written or approved by a human. The AI driver contract has no method that produces candidate-facing messages. | M3/M4 (kickoff §5.7) |
| D-91 | No background worker can set `rejected_by_human`; enforced now by `StatusTransitionGuard` (human actor + reason required). | Now (tested) |
| D-92 | Raw `.eml` is stored encrypted with its SHA-256 before any parsing (`intake_messages.raw_path`, `raw_sha256`). | M2 |

## Open questions (do not block Milestone 1)

- `Q02` real job titles/criteria — only clearly-marked demo jobs are seeded (local env only).
- `Q05` retention values — placeholders in settings.
- `Q07` real users/roles — synthetic `@example.test` users seeded in local/testing only.
- Whether "Restricted" grants for management/recruiter exports should exist at all — waiting for factory approval.
