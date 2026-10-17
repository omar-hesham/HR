# Milestone 4b verification — 17 October 2026

Branch: `m4b-privacy-ops`; base: `c048afc` (M4a). Work is uncommitted.
Scope: items 10–17 only. This is offline implementation verification with synthetic data,
not Egyptian production, legal, training or go-live acceptance.

## Final gates

Verified by the orchestrator after the implementer run stopped on a usage limit (17 October 2026): `pint --test` passed; Larastan level 6 0 errors; `php artisan test` 547 tests — 546 passed, 1 skipped (LibreOffice), 3,951 assertions; `migrate:fresh --seed` passed; `recruit:go-live-check` runs and lists the factory inputs still missing.
PHP: 8.4.26. No package, baseline, disabled assertion or new skip was added.

## Requirement and evidence map

| Requirements | Implementation and synthetic verification |
|---|---|
| M10-P0-02 / PRIV-P0-03 / T-M10-04 | RetentionPolicy and daily RetentionSweep (3 attempts). First final decision stays fixed through viewing/archive; idle clocks use meaningful activity and original receipt. All six active statuses stay on the owner due list at ordinary expiry. Hard cap follows original/merged identity receipt and never extends. Notice and operations alert precede the cap; cap erases active identities. Invalid periods fail safely. |
| T-M10-02 / T-ARC-02 | Shared source and extraction remain for the live sibling while ended application-specific data is erased and its identity detached. Hired switches track in ApplicationStatusService; authorized handover produces an encrypted artifact; handover expiry erases the internal copy. |
| PRIV-P0-03 / T-M10-05 | DeleteCandidateData is the single entry for retention, hard cap, approved erasure and restore replay. Transactional descendants/identity/audit scrubbing, durable file purge and opaque ledger. All tables are searched for the synthetic name, email, phone and quote, including recursive JSON properties and both encryption formats. Stale extraction/scoring and identical source bytes cannot recreate deleted content. |
| Ledger failure safety | Configured separate publication must succeed before the DB erasure commits. Publication is locked and merges already-published authorized intents; DB rollback or an older DB snapshot cannot remove them. A failing publication rolls back DB erasure and leaves the source file intact; retry succeeds. File-purge failure leaves durable work without reviving identity. |
| PRIV-P0-04 / M10-P0-03 | Scoped registration, minimal identity-verification note, approver-only decision and immediate synchronous approved erasure across all statuses. Access artifacts include original-file bytes and subject history; encrypted, audited, 24h expiry. Rectification uses the existing allowlist/evidence action. Notification is manual and logged. Read-only mailbox/provider/downloaded-export responsibilities are explicit. |
| M10-P0-04 / T-M10-03 | ProcessingRecord exports purpose, categories, recipients, storage, criteria versions and retention from settings/DB, with an explicit no-legal-certificate disclaimer. |
| M11-P0-02 / T-M11-02 | PHP logical DB dump + private files + M4a export disk in a separately keyed encrypted archive; native ZipArchive, thin Bash wrapper, 2 nightly attempts. Restore compares all rows (including audit) and file hashes before current-ledger replay, file purge and OPEN. Tests read the resulting SQLite DB and verify missing erased text/files. Separate published-ledger replay is tested with live ledger rows removed. Calling connection/config/storage adapters are restored. Current-schema-only unqualified table capture and exact pre-write table-set validation prevent source-schema targeting on MySQL. |
| M11-P0-01 / T-M11-01 / T-M9-01 | SystemHealth unifies mail poll age, AI/breaker, oldest queue age, safe failed-job metadata, disk, backup freshness and outbox failures. Dedicated jobs.retry defaults to owner/operator; allowed retry is audited; uncertain notices require their own procedure. Six-role page matrix and coordinator HTML assertions prove no candidate PII/payloads/exceptions. Backup health feeds the existing KPI page; old health routes remain compatible. |
| SC-P0-06/07 / T-SC-04 / T-ACC-04 | Per-job blind inputs lock before results; original-CV download exposes no tool ranking. Tests cover exact average-rank Spearman, top-5, suitable-not-presented, ties, insufficient/constant/reversed samples, mixed-job denial and twenty synthetic ranks. Immutable week-3 decision supports rules-only: current rankings cleared, AI refused for that job, revision-bound human field verification, manual schema entry, deterministic scoring and template explanations. Real-pilot selection is refused locally. |
| DEL-P0-02/03 / OPS-P0-04 / DEV-P0-05 | Arabic RUNBOOK-ar.md, USER-GUIDE-AR.md outline/training skeleton, DEPLOYMENTS.md and GO-LIVE-CHECKLIST.md. Read-only recruit:go-live-check verifies local configuration/DB/document predicates and warns on defaults; external approvals remain explicit. Runbook follows §11.3 compatible code-only rollback preserving new inbound applications. |
| M1–M4a / T-ACC-01/02/03/05 | Existing tests retained without weakening. M4a changes are limited to retention/activity/deletion safety, rules-only integration and the backup KPI hook. No automatic rejection or new candidate-facing email path. |

## Entrypoints

- `/admin/privacy-requests`: registration, verification, decision/execution, download and manual notification.
- `/admin/retention-due`: owner due list, hard-cap notices and hired handover.
- `/admin/system-health`, `/admin/backup-operations`, `/admin/calibration`, `/admin/privacy-operations-settings`.
- `recruit:backup`, `recruit:restore-verify {run} {--ledger=}`, `recruit:privacy-maintenance {--sweep}`,
  `recruit:go-live-check {--json}`. Scheduler includes daily retention/nightly backup and minute maintenance.

## Review and operational boundaries

D-165–D-177 record the implementation choices. Project execution workflow, code guard, test guard and
docs guard were applied. API calls were checked against installed Laravel/Filament source; no new dependency.

The .env secrets and its existing database were not changed or migrated. All destructive gate work
used NEW local SQLite databases; no server, real CV, live AI, mailbox or SMTP delivery was used.
Restore artifacts are local synthetic drill directories under `storage/app/private/ops/restore-*`.
The production restore path requires a matching release, current separately published ledger, a separately
named EMPTY `recruit_restore_*` MySQL database different from live, and separate credentials without live
DB grants. That MySQL path is implemented but was not exercised against a server in this run.

Open external acceptance: Q02 seven real jobs; Q03 real 20-CV sample only in Egypt; Q05 retention/idleness/
hard cap/backup cycle; Q14 thresholds/metrics and manager week-3 decision; rights/provider/legal approvals;
server owner/domain, Egyptian residency, TLS/egress and key recovery; real mail/alert smoke; actual Egyptian
MySQL restore; training, synthetic screenshots and signed minutes. Guide/runbook placeholders are intentional
factory inputs. No commit, push or deployment was performed. Local passing tests do not approve go-live.
