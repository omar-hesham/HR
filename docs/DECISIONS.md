# Decisions log — Milestones 1 & 2

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
| `webklex/php-imap` | ^6.0 (6.2.0, Milestone 2) | Named in kickoff §3 (no `ext-imap`): password IMAP over TLS and XOAUTH2 for M365/Google. Also used **offline** to parse raw `.eml` (`Message::fromString`) so every driver shares one MIME parser. |
| `pestphp/pest`, `pestphp/pest-plugin-laravel` (dev) | ^4 | Fixed decision (tests). |
| `larastan/larastan` (dev) | ^3.13 | Fixed decision (static analysis). |
| `mpdf/mpdf` (dev only) | ^8.3 | **Not named.** Needed to generate synthetic Arabic/English/password-protected/image-only PDF fixtures in pure PHP because LibreOffice is not installed on this PC. Dev-only; never loaded at runtime. |

Not installed: `laravel/ai` (Milestone 3), `opis/json-schema` (Milestone 3), `laravel/boost` (the Laravel 13 skeleton's `CLAUDE.md`/`AGENTS.md` ask agents to install it; not in our allowed list, so those two skeleton files were replaced with project notes).

## Mail intake (Milestone 2)

| # | Decision | Reason |
|---|---|---|
| D-100 | `MailboxDriver` contract returns raw `.eml` bytes + external id only; parsing always happens in our code via `WebklexMessageParser` (`Message::fromString`). | One code path for fake and real drivers; raw-first is enforced by construction. |
| D-101 | Provider map: `m365` → XOAUTH2 client credentials, `google` → XOAUTH2 refresh token, `imap` → password over TLS, `fake` → `.eml` folder in `mailboxes.host`. | Fake-driver integration tests and mocked vendor/HTTP contracts only; no live connection in this milestone. |
| D-102 | IMAP uses the installed Webklex protocol directly: `EXAMINE`, `UID SEARCH`, `UID FETCH BODY.PEEK[] INTERNALDATE`. No `Message` objects are created by the driver; raw bytes remain unchanged. No move/copy/delete/flag/expunge commands. | The higher-level Webklex query parses before returning, violating raw-first; `openFolder()` actually uses SELECT. |
| D-103 | Identity is `(mailbox_id, UIDVALIDITY:UID)` for IMAP, filename for fake. Hash and Message-ID dedupe consider completed receipts only; pending receipts resume their unfinished attachment rows. The pointer advances through the successfully stored prefix and stops at the first failure, which is rethrown for queue retry. | Prevents a failed raw write or scanner outage from turning a pending receipt into a skipped duplicate. |
| D-104 | CV extensions and recognized CV bytes without an extension enter validation/scan; image extension/MIME goes to review. Inline images are logged, inline CVs still enter intake. Forwarded and unknown formats go to review; known non-CV formats are logged as ignored. | `M1-P0-02`: an unknown file must not disappear just because its name lacks CV keywords. |
| D-105 | Inline cid-referenced images get a logged `document_sources` row (excluded, reason `inline_image`) but no review item; real attachments always get rows, and images/uncertain items open review. | `M1-P0-03` visibility without flooding Needs review with e-mail signature logos. |
| D-106 | Image attachments are never stored as separate documents and never OCR'd: metadata row (encrypted filename, mime, size), reason `image_attachment`, review opened for a human to decide. The encrypted original `.eml` still contains its MIME parts; raw-message retention and ID-photo policy require review before real data. `privacy.keep_id_card_attachments` does not strip parts from originals. | `M1-P0-05` ID photos default non-retention; `M2-P0-01` "CV images remain in review without OCR". |
| D-107 | Malware scan lives in the shared `StoreCvFile` action, so both upload and mail channels scan; ClamAV behind `MALWARE_SCANNER` env (`null` locally — clamd is not installed on this PC; `clamav` required outside local/testing; misspelled scanner drivers fail closed). Infected → encrypted quarantine under `storage/private/quarantine/{sha256}.bin`, source excluded with reason `malware_detected`, review opened, never extracted. | `M2-P0-02` applies to uploads too; brief §4. |
| D-108 | Ignored messages (no CV, nothing to review) drop the stored raw `.eml` after the decision (hash + size + metadata kept); processed/needs-review messages keep it encrypted. | Spec §9.3 "ignored records: a minimal record without the body". |
| D-109 | A sender with a linked CV application gets one Arabic/English notice reservation per configurable window across all mailboxes, including mixed messages needing review. Auto-generated/bulk senders are suppressed. Key = SHA-256(sender HMAC + intake row id). Sender reservation and delivery have locks. | The window includes pending, sent and uncertain notices, avoiding duplicate submissions and parallel mailbox races. |
| D-110 | Notice delivery uses `MAIL_SEND_TRANSPORT` (default `array`, no delivery or message logging). Approved SMTP uses the separate `notice` mailer with `MAIL_SEND_*` credentials. The queued outbox job sends synchronously, then marks sent; the mailable is not separately queued. | Separate intake/sending credentials; avoids reporting success before a second queued mailable actually sends. |
| D-111 | A re-sent infected file is re-scanned and quarantined under the same SHA-256 path; each receipt gets its own excluded source row (visible), no document. | Keeps accounting simple; quarantine storage never grows on repeats. |
| D-112 | New mailboxes default `start_at` to creation time; old null pointers are initialized from `created_at`. IMAP filters and records health/received_at using server INTERNALDATE, not the spoofable sender Date; fake fixtures use their Date header. | Prevents archive import, with date/time handling covered offline. |
| D-113 | Poll queue `intake`: per-mailbox unique lock and overlap middleware, 3 tries, backoff 60/300 s, timeout 290 s; database reservation defaults to 600 s. Poll command every five minutes also recovers pending outbox jobs; silence monitor hourly, once per silence period using `last_silence_alert_at` and the configurable threshold. | Queue reservation exceeds the timeout; durable pending notices survive interruption before dispatch. |
| D-117 | Use the existing dependency-free clamd INSTREAM client rather than adding quahog, which requires unavailable `ext-sockets`. Writes handle partial progress; replies require NUL framing and exact clean/infected matches. | No additional packages or extensions; wire framing and verdict parsing are tested without a live daemon. |
| D-114 | Default notice subject/body live in `lang/{ar,en}/app.php`; `SettingsSeeder`-style defaults come from config as empty strings and `SendPrivacyNotice` falls back to lang when the setting is empty. | CLAUDE rule: no Arabic literals in PHP config/code. |
| D-115 | `sender_hmac` = HMAC-SHA256 of the lower-cased From address with `DEDUP_HMAC_KEY` (env; generated by `recruit:key`). The From address itself is stored encrypted (`sender_enc`). | Spec §9.5 `DEDUP_HMAC_KEY`; window checks need a stable pseudonymous key, the address stays private. |
| D-116 | Job hint: `JobResolver` search-normalizes subject + plain-text body and matches job `title_ar`/`title_en`/aliases (normalized substring). Exactly one match → `job_hint_id`; zero or many → unassigned application (recruiter resolves). | `M1-P0-02`; model classification of ambiguous mail is M3. |
| D-118 | Optional `mailboxes.credentials` uses Laravel `encrypted:array` and is hidden from serialization. `credential_ref` remains a non-secret config reference; the default reads MAILBOX_* through Laravel config, which works after config caching. | The task requires encrypted mailbox credentials; getenv alone misses Dotenv values and config caches. No real credentials were used or `.env` values changed. |
| D-119 | Poll and notice failure records contain safe reason codes only; vendor exception messages/previous exceptions are never passed to logging or failed-job storage. | IMAP/OAuth/SMTP errors can contain credentials or recipient data. |
| D-120 | Notice states are pending → sending → sent; transport uncertainty/crash leaves uncertain/sending for review and prohibits automatic resend. The health failed count includes unconfirmed notices. | Spec §9.4 explicitly bars automatic retry after an uncertain send outcome. |
| D-121 | Intake processing uses a short-lived global cache lock in addition to mailbox overlap locks. Per-file source accounting is durable and resumable; rejected/quarantined review metadata is saved atomically. | At this volume serializing intake protects cross-mailbox hash/Message-ID dedupe while preserving retry progress. |
| D-122 | Keep the M2 recruit:key extension to generate a missing DEDUP_HMAC_KEY; remove its unused KEYS constant. Existing keys remain untouched; the command was not run during repair. | HMAC initialization is required for M2, so restoring the entire M1 implementation would remove necessary setup. |
| D-123 | M2 migration/seed validation uses a newly created, isolated SQLite file and process-scoped safe settings. The full regression suite uses in-memory SQLite. | The brief forbids connecting to a real service or touching secrets; MySQL/live provider acceptance remains a separate authorized check. |

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

## Milestone 3 — decisions (17/10/2026)

| # | Decision | Reason |
|---|---|---|
| D-124 | Spec deviation: city/governorate remain required schema keys but MUST be null, never extracted/stored/scored as values. | M3 orchestrator resolution; kickoff §5.3 overrides spec §7.1; no approved location criterion can exist. |
| D-125 | [INFERRED] Extract document once using the first application's job context; another job reuses it and may lack relevant experience for that job. | Q4 resolution; no invented cross-job relevant years or re-extraction. |
| D-126 | [INFERRED] Salary comparison requires matching configured currency and period; otherwise unclear. | Q6 resolution; no exchange rates invented. |
| D-127 | [INFERRED] Versioned prompt/schema files, versions recorded on attempts/scores, no prompt DB table. | Q7 resolution; templates reviewed in code; no raw prompt persisted. |
| D-128 | [INFERRED] Conservative chars-to-token reservation plus fixed output allowance and configurable EGP token rates; full reservation charged for unknown failures, actual usage bounded by reservation. | Q8 resolution; prices are estimates until legal/provider choice. Cap defaults to 300 EGP. |
| D-129 | laravel/ai and opis/json-schema are the only new direct packages. SDK openai-compatible connection is the intended local adapter; acceptance against the actual server remains open (Q9). | Official SDK documents local compatible endpoints; no silent HTTP fallback and no live endpoint here. Transitives: opis/string and opis/uri support schema parsing/formats; symfony/yaml is required by the SDK. |
| D-130 | AI pipeline defaults off; AI_DRIVER selects fake/local/cloud through config, never auto-fails over. Cloud additionally needs permission-managed legal reference, verified scoped consent and successful PHP masking. Q04 signer unresolved. | Q1 does not block construction; explicit server settings boundary ai.pipeline.manage, separate from settings.business. |
| D-131 | Threshold defaults 70/40 and confidence .80 are settings, snapshotted on approval with education list and synonym dictionary. Existing approved versions missing snapshots require configuration review. | Q2/Q3 unresolved; synthetic illustrative criteria only, calibration deferred to M4. Dictionary changes must not change an already-approved version's deterministic result. |
| D-132 | New companion tables hold consent ledger, durable waiting work, provider state, encrypted masking context, corrections and evaluation keys; no existing table/enum changes. | Missing controls cannot be safely put in logs or queues; existing M1/M2 records remain compatible. |
| D-133 | Database queue consumption is gated before pop. AI jobs use queue ai; deterministic score/rescore jobs use scoring; recovery probes/dispatch run outside both. | Breaker waits consume no attempts/reservations and deterministic processing continues. Failure threshold defaults 5; recovery 60 s; one leased half-open trial. |
| D-134 | Strict deterministic name rules: labelled name/header, sender display name and non-generic filename tokens; reject conflicting/ambiguous identities. Digit normalization is matching-only; substitution map retains original glyphs. Address/signature lines are locally masked. | No local model needed; masking makes no claim to capture other people's names or guarantee anonymization. |
| D-135 | Model explanations select/order an allowlist of complete stored Arabic claim strings and evidence references, verified exactly and rendered locally; free additions are invalid. Deterministic full explanation is saved with the score immediately. | Reliable claim confinement without another LLM; failure/defer never stalls scoring; never candidate-facing text. |
| D-136 | Corrections are append-only, encrypted before/after facts plus actor/source/reason, scoped to application; reevaluation creates a new immutable score set. Overrides append original/new scalar values and mandatory reason without mutating results. | SC-P0-05, ARC-P0-02; UI deferred to M4. |
| D-137 | Ambiguous intake classification sends only masked subject/body text, no attachment bytes/images/filenames/metadata; classification suggestions stay in visible review and cannot bypass malware/file validation or identify another person's CV. | M1-P0-02/05 safety; unsupported unknown attachments cannot safely be promoted by a text-only model. |
| D-138 | A boolean disqualifier may explicitly target `value=false` (confirmed unwillingness); the fact remains `can_reach_plant_for_shift`, human-answered only. C8's affirmative acceptance therefore yields not_met. | Represents the §7.5 example without adding location extraction or changing enums. |
| D-139 | SDK v1.2.3 contains a hidden Retry-After retry. Use its gateway with that retry disabled and redirects disabled; all retries/reservations belong to our queue. Application-generated X-Request-ID is recorded because SDK Meta does not expose provider response ids. | Exactly one wire attempt per ai_runs row; no hidden fourth/sixth request. Local HTTP is limited to loopback; private-network endpoints and cloud require TLS. |
| D-140 | Persist actual-dispatch attempts atomically with the reservation, before the network boundary; uncertain/crashed reservations stay fully counted. Re-dispatch cannot exceed three provider attempts. | Hard timeouts must not reset durable retries or monthly accounting. |
| D-141 | Verify numeric experience against explicit duration quotes, or complete non-overlapping employment-period union for total experience; incomplete dates never become years. Unsupported values get confidence zero and a visible review code. | Evidence existence alone must not confirm an invented numeric value. |
| D-142 | Operator alerts always persist in the operations activity log/banner. A bounded default-queue alert job uses the existing approved HEALTH_ALERT_RECIPIENT/notice transport when configured; no applicant data. | Alert delivery must not block intake, breaker state, or recovery. No recipient is fabricated. |
| D-143 | Score/result/correction/override history is immutable at the model boundary. A companion correction revision lets the independent scheduler recover a lost score dispatch. | Closing the unique-job/correction race without re-extraction or overwriting prior evaluations. |
| D-144 | The illustrative C1 education-level target includes an optional explicit `field` specialization, evaluated against the same education entry through the approved synonym dictionary. | Electrical diploma or higher must not be satisfied by an unrelated diploma; keeps the worked example at exactly eight criteria without new enums. |
| D-145 | Health probes also count closed-state failures; they run outside ai every 60 seconds, with a 10-second SDK ping and a short independent lease. Half-open permits only one real work trial. | A health-only outage must open the breaker, and recovery must not wait behind the 600-second extraction timeout. |
| D-146 | Missing/invalid cloud usage retains the full reservation and a usage_unavailable review code, rather than releasing the estimate as zero cost. Waiting work is recovered in chunks without a fixed leading-item limit. | Missing usage must not bypass the cap; old non-dispatchable work must not starve later applications. |

## Milestone 4a — Review and decisions

| # | Decision | Reason |
|---|---|---|
| D-147 | Split M4 into independently gated M4a review/decisions and M4b privacy/ops/go-live. Only items 1–9 are implemented here. | Explicit orchestrator scope; M4a alone cannot satisfy DEL-P0-03. |
| D-148 | Select `phpoffice/phpspreadsheet` from spec 9.2 for RTL, explicit phone text and formula control. Backups will use Bash + artisan with no package in M4b. | Explicit orchestrator decisions; no backup package authorized. |
| D-149 | Ranked, compare and export identity follows user OR job name-blind. Identified exports extend M8's list with name, phone and email; omit all three under name-blind. Detail retains identity for authorized viewers. | Explicit inferred default; TXT-P0-05 contact usability without an export bypass. |
| D-150 | Evidence preview uses normalized Unicode code-point coordinates, escaped text and stored quote verification; no PDF rerendering. Compare redacts identity from quotes under name-blind. | Explicit inferred default; anchored evidence and no HTML/identity leakage. |
| D-151 | Weekly summary stays in-tool, without PII or emailing; screening time is a manual weekly pilot entry in minutes. Metric counting units are stored explicitly. | M8-P0-02 and G-P0-01; no user-time surveillance. |
| D-152 | Pinned paginated unscored/needs-review sections ignore main-list filters and sorting. General ranking groups jobs and ranks only within each current version, with deterministic id tie-break. | Nothing disappears; no invented shared cross-job ranking. |
| D-153 | A score on a replaced document is unranked even if its criteria version is current. Override values display separately; original deterministic score/band determines rank. | Preserve provenance, SC-P0-03 and historical results. |
| D-154 | Duplicate actions require review.manage, a non-empty reason, a current shared contact HMAC and authority over ALL applications of both identities. Keep-two resolutions are keyed by the current document/contact fingerprint. | No name-only merge, scope escalation or stale resolution. |
| D-155 | Merge reparents documents and applications, retaining distinct same-job application and score histories and a merged alias without applications/documents and with an opaque destination link. Append additionally activates the incoming CV after explicit recalculation confirmation. | No deletion/history loss in M4a; link snapshots plus actor and encrypted reason are append-only. M4b must follow alias links when erasing. |
| D-156 | Broad exports (exports.all) require password confirmation within 15 minutes on generation and download. Artifacts are creator-only; scope and blind flags are rechecked at download. | SEC-P0-01, revocation and name-blind safety. |
| D-157 | Export bytes are encrypted using the existing content key on a dedicated disk rooted at storage/private/exports, inaccessible from public storage. Expiry is 24 hours; a per-minute command removes expired bytes, keeping opaque audit metadata. | Explicit M4a expiry authorization, ARC-P0-04; no general retention/deletion machinery. |
| D-158 | CSV prefixes formula-like lead-ins with an apostrophe; XLSX writes all textual cells explicitly as text and sets quote-prefix for risky cells, preserving exact phone characters. Numeric score/coverage alone use numeric cells. | Formula safety and round-trip +/leading-zero preservation without altering phone text. |
| D-159 | Human status policy is enforced inside ApplicationStatusService, under an application lock. Recruiters get review/follow-up only; owner/HM can record final decisions; only owner may reopen with reason. UI exposes the guard/policy intersection. | Authorized humans only; no new status or candidate messaging. |
| D-160 | Reporting uses the same application scope; owner/ops may see global PII-free intake metrics, including unlinked files/messages. Other reviewers see linked in-scope units only. Visibility rows include every authorized message, pending visibility and worst-case age; p95 is supplemental. Backup health remains explicitly unavailable until M4b. | Avoid indirect cross-job disclosure and false KPI claims; privacy/ops remains out of scope. |
| D-161 | Export reasons are rendered in Arabic from the score summary and criterion labels/outcomes only. The stored reason_enc contains verbatim evidence, so it is deliberately not exported. Historical detail evaluations are selected through an application-scoped score id. | M4a exports prohibit CV text/evidence quotes; history must remain independently inspectable. |
| D-162 | Review query methods on Livewire pages are protected and supplied through server-side view data. Full normalized CV text additionally requires the document download policy, including on detail pages. Every overlapping quote start retains its own evidence anchor. | Prevent calling a rendering helper as an RPC to bypass name-blind or file permissions. |
| D-163 | Local/testing seeding includes seven synthetic DOCX review examples: current scores, a contact-conflict pair, a low-quality flagged case, an unassigned case and a historical score. Seeded facts are explicitly synthetic and scoring is local; no model/mailbox is called. No synthetic review data is seeded in staging/production. | Make the weekly routine demoable offline without real CVs or misleading live-provider claims. |
| D-164 | PhpSpreadsheet 5.10.0 requires maennchen/zipstream-php 3.2.2, markbaker/complex 3.0.2 and markbaker/matrix 3.0.1 transitively. No other direct dependency is added. Export metadata is registered before writing bytes so even a failed write remains eligible for expiry cleanup. | Composer-resolved writer/math dependencies; avoid orphaned encrypted export bytes. |

## Milestone 4b — Privacy, operations and go-live

| # | Decision | Reason |
|---|---|---|
| D-165 | M4b implements items 10–17 only on m4b-privacy-ops, retaining M4a c048afc and its tests. Existing name-blind/export behavior is preserved. | Explicit split and reuse instruction; no commit/push/deployment authorized. |
| D-166 | All retention, hard-cap, DSAR and restore erasures use DeleteCandidateData. DB changes use transactions; when configured, separate ledger publication must succeed before commit and published authorized intents are merged monotonically across retries/rollbacks; opaque file-purge tasks survive I/O failure; completion waits for cleanup. Personal descendants and audit properties are scrubbed; opaque tombstones remain. | Highest-risk path; synthetic full-database decrypted-string scan is a gate. Files cannot roll back with a DB transaction. |
| D-167 | Ordinary expiry erases only an ended/idle application's unshared records; a live sibling keeps its shared source. The ended application is detached to a blank tombstone. Weekly candidate counts exclude erased/tombstone identities so erasure does not invent extra people. Erasure and hard cap follow merged aliases. Original receipt and first final decision never extend. | PRIV-P0-03, D-155; all active statuses protected at ordinary expiry. |
| D-168 | Hired switches to hired_handover in the sole status writer. Authorized manual handover creates an encrypted, audited 24h artifact and records handed_over_at; expiry erases the in-tool copy. Employee-file custody stays outside this tool. | Kickoff 5.5; no HR/employee module. |
| D-169 | In-tool hard-cap notice plus PII-free operational alert is durable; daily sweep has three attempts. Missing prior scheduler runs do not extend the hard maximum. | Explicit cap, advance-notice and safe-failure requirements. Operational owner must act on failed scheduling. |
| D-170 | Rights requests separate registration from approver verification/decision; requester requires authority over every application of the identity. Approved erasure executes synchronously, independently of the daily sweep. Retry remains available for incomplete approved execution. Notification is manual and audited, with no new candidate messaging. | PRIV-P0-04 / T-M10-05; channel is recorded, mailbox/provider/export responsibilities explicit. Erasure clears free-text identity-verification details. |
| D-171 | Subject access includes identity aliases, applications/history/corrections, extracted data and original-file bytes in an encrypted JSON artifact with creator-authority rechecks and 24h cleanup. Processing-record export reads explicit settings and approved criteria versions and disclaims legal certification. | M10-P0-03/04; no extra package or delivery channel. |
| D-172 | Backups use PHP artisan and native ZipArchive, with a thin Bash wrapper. The portable logical database dump is JSON rows plus the exact versioned migration fingerprint; private files and M4a export disk have per-file hashes. A distinct BACKUP_ENCRYPTION_KEY encrypts the archive. No backup package is added. | Explicit portable-tooling decision; Linux server / Windows workstation; keys and .env are excluded. Approved backup rotation is external, never inferred as 7/4. |
| D-173 | Restore verifies all database rows (including audit) and files in isolation, then replays the CURRENT separately published encrypted ledger and purges files before writing OPEN. Local/tests use fresh SQLite directories; other environments require an empty recruit_restore_* MySQL target different from live. Matching release required; dump table names are unqualified and the complete table allowlist is validated before restore writes, preventing MySQL source-schema targeting; restore engine recorded and checked at go-live. | T-M10-02 / T-M11-02 / T-ARC-02. No server is contacted in this run; production-engine/residency acceptance remains Egyptian ops work. |
| D-174 | Add jobs.retry to owner/operator (inferred default), backups.manage to owner/operator and calibration.manage to owner/HM. Old health routes stay compatible but leave navigation; SystemHealth unifies them. Failed payloads, exceptions, secrets and candidate data never appear. Uncertain notice sends cannot use general retry. | M11-P0-01; existing restricted matrix cells remain unchanged. |
| D-175 | Blind calibration is per job/current criteria version. Inputs lock individually before results; metric snapshot locks after the final input. Spearman is Pearson of average ranks; tool score/coverage ties use average ranks; top-5 boundary ties use opaque application id. Small samples and undefined correlations are explicit. Original CV download links expose no tool ranking. Real-pilot selection is refused outside production and requires a recorded Egyptian residency approval. | SC-P0-06, no shared job ranking. Thresholds remain proposals awaiting Q14 approval. |
| D-176 | Week-3 decision is insert-only. Rules-only clears current scores, prevents AI work for that job, requires revision-bound human field verification, uses deterministic scores and template explanations, and preserves old history. | SC-P0-07; later correction invalidates earlier verification. No automatic rejection. |
| D-177 | Go-live checks are read-only database/config/document checks, not live service probes or legal/training acceptance. Retention defaults always warn; approval references and named owners must be supplied. Guides and rollback docs describe compatible code-only rollback preserving new inbound applications. | DEL-P0-02/03 and §11.3. Factory approvals, real sample, training, Egyptian hosting and signed minutes are external. |

## Demo tour and presentation banner (18/10/2026)

| # | Decision | Reason |
|---|---|---|
| D-178 | Eleven-step first-use tour starts on the applications list after login/MFA; a locked Livewire host supports replay from the user menu on any authenticated panel page. Finishing and skipping stamp only the authenticated usable, MFA-enrolled user, once, in nullable users.tour_completed_at. No user id, timestamp or open HTTP endpoint is accepted. | Per-user onboarding without changing authentication, permissions or review behavior. Replay does not reset the original completion time. |
| D-179 | Filament render hooks load local dependency-free Alpine JS/CSS; semantic targets use actual sections/actions/navigation, with centered fallback when absent or hidden. The dialog traps focus, supports Esc and direction-aware arrows, honors reduced motion, and cleans observers/listeners on Livewire navigation. All copy is in lang/ar and lang/en. | RTL/LTR and keyboard support without packages, a CDN or automatic navigation/actions. |
| D-180 | APP_DEMO_BANNER defaults false in config/app.php and .env.example. When true, a fixed non-dismissible translated synthetic-data warning appears on every panel layout, including login/MFA, and directly above the upload field. A measured CSS offset keeps panel navigation below it. | Public presentation warning only; it does not disable uploads or assert that a supplied file is synthetic. Existing data/auth/AI guards remain unchanged. |
| D-181 | Migration/seed acceptance for this change uses a newly created isolated SQLite database with process-only synthetic keys and safe drivers. The current local database and .env are untouched. | Validate schema/seeding without resetting existing local data or contacting providers. |
