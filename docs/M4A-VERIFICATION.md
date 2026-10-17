# Milestone 4a verification — 17 October 2026

Implemented on `m4a-review-decisions`, based on `76f9a4d`. Changes remain uncommitted for Omar's review.
Scope is brief items 1–9 only. This is offline code verification, not production, browser, legal or factory acceptance.

## Final gates — PHP 8.4.26

| Gate | Actual result |
|---|---|
| `php I:\factory\tools\composer.phar lint` | Passed; Pint, zero style failures |
| `php I:\factory\tools\composer.phar stan` | Passed; zero errors, Larastan level 6, no baseline |
| `php I:\factory\tools\composer.phar test` | **500 tests: 499 passed, 1 existing skip**, **2149 assertions**, **215.446 seconds** |
| `migrate:fresh --seed --no-interaction` | Exit 0 on a NEW isolated SQLite database; 49 tables, 6 synthetic users, 1 illustrative job, 7 applications, 7 encrypted DOCX documents, 5 scores |

The suite includes all 426 existing M1–M3 cases and 74 new M4a cases. No existing test was weakened.
The existing LibreOffice-dependent skip remains; no M4a test skips.

The final gate batch ran sequentially, taking 270 seconds. Logs are retained locally in
`storage/m4a-final-{lint,stan,test,migration}.log`; machine-readable records are
`storage/logs/m4a-verification/{gates,migration}.json`.

The migration gate uses `storage/logs/m4a-verification/migrate.php` to invoke the same Artisan command
after selecting a newly created SQLite file and isolated private disks in that process. It does not
use the database selected by `.env`. The retained database is
`storage/app/private/m4a-gate-d982e46453fcba66/gate.sqlite`.
Independent seed checks found **4 currently ranked applications, 1 stale score, 1 unresolved duplicate pair,
0 missing document files, and 0 AI runs**.

## Requirement traceability

| Requirement | Implementation and evidence |
|---|---|
| M7-P0-01/02, SC-P0-03, T-M7-01 | `ReviewApplications` and the existing `RankedApplications` helper rank only current-version/current-document scores. General lists group jobs; each band orders S, C, receipt, then id. Unscored/review and unranked rescore sections have independent pagination and ignore ranking filters. A historical S=99 stays unranked while recalculation appends a current S=65; originals remain. History never changes the ranking query. |
| Name-blind, T-M9-01 | User OR job flag hides identity in ranked/pinned lists, comparisons and exports. Comparison evidence redacts known identity/contact tokens. Rendered HTML is tested with either flag and with flags off. Detail identity remains subject to application access as specified. Query helpers are protected, closing a possible Livewire RPC bypass. |
| M6-P0-02, T-ACC-02 | `ApplicationDetail`, `ScorePresentation` and `EvidencePreview` show four distinct outcomes, confidence, source, quotes/anchors, reasons, version history, timeline, notes and corrections. Highlights verify the exact normalized Unicode slice, escape HTML and retain each overlapping quote anchor. Historical selection is scoped to the application. Full text uses the selected document's download policy; stored enrichment uses M3's dedicated content key. |
| M7 comparison | Two or three applications must belong to the same job and score version. Direct requests recheck scope. A translated error refuses cross-job/version selections; a version selector permits valid historical comparisons. Scored rows in the rescore section are selectable too. |
| M3-P0-02/03, T-M3-04 | `DuplicateQueue` matches contact HMACs, never name alone. `ResolveDuplicate` locks identities/applications and requires review.manage, full visibility of both people's applications, a mandatory reason and current contact match. Merge/keep-two/append-active are tested via service and UI, including forged ids, replay/stale matches and hidden applications. Encrypted append-only link snapshots preserve histories; access activity identifies actor and prior/new active documents. Activation explicitly confirms recalculation. |
| M7-P0-04, WF-P0-02, T-M7-02, T-ACC-03 | `ReviewAccess` supplies the guard/policy intersection. The sole `ApplicationStatusService` rechecks locked state and human authorization. Recruiters cannot reject or make final decisions; HM cannot reopen even with a direct reopen grant; owner reopening needs a reason. Workers cannot reject. Notes are encrypted, attributed and internal. Interview only records a status; no candidate messages are introduced. |
| M7-P0-03, SC-P0-05 | Thin correction/override forms use the existing actions. Picker paths stay inside the M3 allowlist; protected/contact/location paths are excluded. Reasons and evidence are required. New correction/override rows preserve actor, original/new values and criterion results. Deterministic S/band remains the ranking value; human override displays separately (D-153). |
| M8-P0-01, TXT-P0-05, T-M8-01 | `ExportApplications` uses PhpSpreadsheet 5.10.0. Reopened XLSX verifies RTL, explicit string phone cells, exact plus/leading-zero text and formula-safe values. CSV has a UTF-8 BOM and sanitizes =/+/-/@ lead-ins, including whitespace. Only ten approved columns are exported, or seven under name-blind. Arabic reasons contain score summaries/outcomes, not stored CV quotations. No notes or CV text are exported. |
| SEC-P0-01, ARC-P0-04, M10-P0-03 | Export creation checks export permission plus job/application scope. Broad export/download requires password confirmation within 15 minutes; wrong passwords are rate-limited. Encrypted artifacts are creator-only, rechecked for current scope/blind flags on download, inaccessible at 24 h, and expired bytes are purged by a per-minute scheduled command. Actor/time/purpose are auditable. Tests cover direct URLs, another creator, revoked job access, changed blind flags, expiry and preservation of unrelated files. |
| M8-P0-02, KPI-P0-01, T-M8-02, T-KPI-01 | `WeeklyReport` separates messages, files, candidate records and applications, with units persisted in `review_kpi_definitions`. Reviewed/scored/decisions count distinct applications; initial receipt alone is not review. Owner/ops can inspect PII-free global aggregates; other reviewers receive linked authorized scope. Every visibility record and pending message remains available, worst delay first; p95 is additional. Exception ages/owner refs, active users and current-month cloud consumption/cap are shown. Pilot minutes are manually entered; backup health explicitly awaits M4b. No summary email exists. |
| M4a docs and synthetic demo | `docs/PLAN.md` contains the pre-build flowchart/self-review; D-147–D-164 record the choices. Paired review translations have parity and literal-key checks. `DemoReviewSeeder` idempotently creates seven local/testing-only DOCX cases without model/mailbox calls. |

## Review walkthrough

On a disposable local database, apply migrations and normal local/testing seeding. Use an existing synthetic
account and enroll MFA. The isolated gate database is verification evidence, not a replacement for local settings.

1. Open `/admin/applications`: inspect pinned exceptions and the separate unranked rescore section before the ranked rows.
2. Change ranking filters and history: unscored items remain; compare two or three scored applications from one job/version.
3. Open a detail page: follow evidence anchors, inspect historical evaluations and record a note/status/correction/override.
4. Open `/admin/duplicate-queue`: compare fields/CVs and resolve a synthetic contact conflict with a reason.
5. Export a job: owner confirms password; an assigned HM needs its normal scope. Enable either blind flag and verify identity columns disappear.
6. Open `/admin/weekly-summary`: inspect counting units, all visibility delays, exception owners, cloud cap and voluntary pilot minutes.

## QA findings resolved

- Fixed a Filament action-name collision before the permission/render tests.
- Split stale scores into a dedicated rescore section and allowed their selection for same-version comparisons.
- Protected rendering queries from public Livewire invocation.
- Applied full-text permission to the actual historical document rather than the active CV.
- Matched the M3 enrichment encryption contract with a dedicated render regression.
- Kept evidence quotes out of exports, despite their presence in M3's stored explanation.
- Excluded upload-created status events from the weekly human-review count.
- Tested merge histories, scope revocation, formula lead-ins and exact phone types with real SQLite/files/XLSX readers.

## Limits and M4b boundary

No commit, push or deployment was performed; no factory server, real mailbox, SMTP or live AI was used.
`.env` secrets and the database it selects were not changed. The four added tables are review/export/reporting
tables only; existing M4b placeholder tables/pages remain without new M4b logic.

M4b retention/deletion and alias-aware erasure, privacy requests, backups/restore, unified system health,
calibration, deployment/runbook/Arabic guide and go-live tooling are deliberately excluded. DEL-P0-03 is not
satisfied by M4a. Real criteria/sample approvals, legal/provider decisions, MySQL 8.4 staging and desktop
browser/Excel acceptance remain separate checks. Automated HTML rendering is not a browser screenshot audit.

API usage was checked against installed source and the official documentation:
[Filament pages/actions](https://filamentphp.com/docs/5.x/resources/custom-pages),
[Laravel password confirmation](https://laravel.com/docs/13.x/authentication#password-confirmation),
[PhpSpreadsheet strings/RTL](https://phpspreadsheet.readthedocs.io/en/latest/topics/recipes/),
[Livewire URL properties](https://livewire.laravel.com/docs/4.x/url),
[Spatie activity](https://spatie.be/docs/laravel-activitylog/v5/basic-usage/logging-activity),
[Spatie permissions](https://spatie.be/docs/laravel-permission/v8/basic-usage/role-permissions),
[PHPWord writers](https://phpoffice.github.io/PHPWord/usage/writers.html).
