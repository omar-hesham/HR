# Milestone 3 verification — 17 October 2026

Implementation is complete on `m3-ai-scoring`, based on `d549d10`, with uncommitted changes for Omar's review.
This records offline implementation verification, not live-provider, legal, calibration or deployment acceptance.

## Gates (PHP 8.4.26)

| Command | Actual result |
|---|---|
| `php I:\factory\tools\composer.phar lint` | Passed (Pint) |
| `php I:\factory\tools\composer.phar stan` | Passed, 0 errors; Larastan level 6, no baseline |
| `php I:\factory\tools\composer.phar test` | 426 tests: **425 passed, 1 skipped**, 1408 assertions, 154.666 seconds |
| `php artisan migrate:fresh --seed --no-interaction` | Exit 0, NEW isolated SQLite database; 45 tables, 6 synthetic users, 1 demo job, 1 fake mailbox, 0 AI runs |

The full suite includes all 344 existing M1/M2 tests and 82 M3 tests. The existing skip remains.
Formatter fixes were followed by a passing lint gate. There are no new skipped tests or baseline suppressions.

Gate outputs are retained locally in `storage/m3-final-{lint,stan,test}.log` and `storage/m3-migration.log`.
The isolated seed database is `storage/app/m3-gate-953f29ac73024d1da60a0d849e717864.sqlite`; its pointer
and safe request-shape diagnostics are retained under ignored `storage/logs/m3-verification/`.
The existing `.env` and its database were not changed. No commit, push, deployment, live mailbox,
SMTP delivery or live AI request was performed.

## Traceability and evidence

| Requirements | Implementation / verification |
|---|---|
| M5-P0-01, ARC-P0-05, D-90 | Exact five-method `AiProvider` contract; Laravel SDK/fake adapters; typed safe errors. SDK system/user separation, temperature 0, one wire attempt per row, matching request IDs and no hidden Retry-After retries tested with HTTP mocks. |
| M5-P0-02, PRIV-P0-05, D3/D11, M9-P0-03 | Pure PHP masking, sender/filename/header identity rules, encrypted reverse map, local deterministic contacts. Each missing cloud prerequisite yields zero requests/runs. An explicitly approved synthetic cloud request is masked; model contacts are discarded. Ordinary settings payloads cannot enable AI; dedicated-permission page saves are tested. |
| M5-P0-03, SC-P0-01, T-SC-01/05 | Opis JSON Schema 2020-12; extra fields, offsets, missing keys, version/confidence violations rejected. Arabic/emoji coordinates are Unicode code points; repeated quotations use page boundaries, absent quotations get confidence zero/review. Numeric durations and complete employment unions checked locally. |
| M1-P0-02/05, D4 | Only hook in M2 processing enqueues ambiguous classification after durable visibility. The masked subject/body is classified; attachment bytes, filenames and metadata are absent. Suggestions remain in human link review. |
| M5-P0-04/05/06, AC01 | Three durable attempts, 30/120 backoff, reservation before dispatch, cap waits, conservative unknown-cost/unknown-usage accounting. Pre-pop breaker gating, separate intake/scoring, operator alerts, independent health, single leased half-open success/failure verified. All 30 outage arrivals remain visible within an hour. |
| M6-P0-01, SC-P0-02/03, T-SC-02 | Exactly 8 worked criteria: outcomes `met, met, met, met, met, not_mentioned, not_met, not_met`; S=65, C=80, needs-review. Missing shift stays review at S=100. Unapproved/no-weight versions yield visible configuration review. Per-criterion evidence and version snapshots tested. |
| M6-P0-02/03, T-M6-02 | Every criterion persists outcome/evidence/confidence/source. Immediate Arabic template; AI may only select/order complete allowed claim IDs. Fabrication rejected; accepted enrichment stored separately without rewriting the score. |
| SC-P0-05, M3-P0-01, M4-P0-02, ARC-P0-02 | Human safety-training evidence yields a NEW S=85 strong score while S=65 remains. Encrypted before/after evidence, actor and reason retained. Criteria approval queues rescoring without extraction. True duplicates enqueue neither extraction nor scoring; different jobs reuse facts and do not borrow relevant years. Overrides preserve original results. |
| ARC-P0-03, §9.3/9.4 | Separate ai/scoring queues, idempotency keys, shared overlap locks, atomic score writes, deletion checks, durable recovery, identifier-only queue payloads. Mid-response deletion and partial-write rollback tested. No CV text in operations/provenance. |
| SC-P0-04, T-SC-03 | Existing protected/location criteria guards stay green. Equivalent bilingual facts and changed name order preserve outcomes. No location values or protected schema fields; military status stays null without its separate legal setting/reference. |

## Decisions and operational limits

D-124–D-146 are in `docs/DECISIONS.md`. They include the orchestrator's location deviation and the four
inferred defaults, conservative reservation/unknown-usage policy, SDK retry handling, constrained explanations,
immutable history/recovery, specialization matching, health probes and operator alerts.

- Cloud is disabled. Q04's written provider/data-flow decision and approval signer remain unresolved.
- The local model/endpoint benchmark, server TLS/egress and MySQL staging migration must be verified in Egypt.
  SDK-compatible HTTP mocks do not establish actual endpoint performance or model quality.
- Q14 thresholds/calibration and Q02/Q03 real jobs/sample are not accepted by synthetic golden tests.
  Calibration and review/compare/export/retention UI remain M4.
- Token rates are inferred estimates. Actual provider billing/prices must be approved before cloud operation.
- Masking does not claim anonymization or reliable capture of other people's names. Ambiguous identity stays local.
- Existing approved criteria lacking M3 snapshots need a new approved version; historical versions are untouched.
- Approved operational email needs its configured recipient/transport. Offline tests do not establish real alert delivery.
