# AI brain demo verification — 18 October 2026

Branch: `demo-ai-brain`. Changes remain uncommitted; no push or deployment.

| Gate | Measured result |
|---|---|
| `vendor/bin/pint --test` | Passed, zero formatting issues |
| `vendor/bin/phpstan analyse --memory-limit=1G --no-progress` | Passed, zero errors |
| `php artisan test` | 625 tests: 624 passed, 1 existing LibreOffice skip; 4,694 assertions; 354.970 seconds |
| `php artisan migrate:fresh --seed --no-interaction` | Passed on a newly created isolated SQLite file; 56 tables, 6 synthetic users |

The 23 new targeted feature cases passed. They cover the Gemini-compatible masked request/JSON mode, usage and conservative EGP reconciliation, six-role mailbox authorization plus MFA, password/M365/Google write-only credential persistence, authentication/EXAMINE without SEARCH/FETCH, sanitized connection failures, scoped/current top-three suggestions, quote redaction/escaping, raw-only import and the normal fake-AI extraction/scoring path. Existing guard and scoring tests remain unchanged and green. The tour count test now expects the added twelfth step.

## Worker execution, independent of test fixtures

A fresh isolated instance used the normal database-backed extraction, AI and scoring workers, with stray HTTP forbidden. Its authorized synthetic actor enabled AI through `SettingsRepository`; the demo command made no permission or enablement changes.

- 12 raw DOCX documents imported through `StoreCvFile` using the review seeder's source text.
- 24 successful recorded fake AI runs: 12 extraction and 12 optional explanation runs.
- 12 scores, all 85; 84 model-sourced criterion results.
- Zero error/defer records, zero pending work, zero queued or failed jobs.
- Synthetic consent recorded through the existing repository. The isolated user's encrypted MFA enrolment was preserved.

The optional `--with-mail --work` run imported 20 documents in total and recorded 27 successful fake AI runs with 12 scores. It drained all queues with zero failed jobs. One extraction for the existing `01-arabic-cv` mail fixture remained in review under `MaskingFailed`; no provider request was sent for that blocked work. The masking guard is unchanged. Other unassigned/review-only fixture outcomes remain available for human review. Mail fixture recordings deliberately provide no unsupported CV facts.

## Boundaries and retained evidence

`database/database.sqlite` had the same SHA-256 before and after both isolated runs. No local migration, reset, credential update or MFA mutation was performed on that database. `.env` was untouched. Encryption keys existed only in each verification process; the cloud key was absent. No real data, live Gemini, IMAP/OAuth account or SMTP delivery was used. Gemini HTTP mocks establish request/meter behavior, not live account access or model quality.

Ignored gate logs: `storage/demo-ai-{lint,stan,test}-final.log`.

- Main SQLite/migration/worker/summary evidence: `I:/factory/recruit-app/storage/app/demo-ai-verification-1113151600904811af9bafa00a360e15`
- Optional mail evidence: `I:/factory/recruit-app/storage/app/demo-ai-mail-verification-4d6f4350cddf4ce88c149f0a92ce7b3b`

The earlier disabled-AI smoke instance intentionally retained `awaiting_service` work and was not counted as completion. The final run above enabled the pipeline through the authorized settings API.

Before using the new mailbox UI on an existing instance, apply the additive migration and permission seeder described in [the run guide](DEMO-AI-BRAIN.md). Launch Gemini with a process-injected key, the synthetic approval reference and an already authorized `ai.pipeline.manage` actor; do not persist the key.
