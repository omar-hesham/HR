# Visible AI demo — local operation

All documents and mail fixtures in this workflow are synthetic. The cards are suggestions for human review; no automatic rejection is added.

Use PHP 8.4 from `I:\factory\tools\php84`. Apply the additive schema/permission update using `php artisan migrate` and `php artisan db:seed --class=RolesAndPermissionsSeeder` on the intended demo instance; neither is `migrate:fresh`. The acceptance gate uses a separate SQLite file, never `database/database.sqlite`.

## Process environment

The launcher must inject these into both artisan and queue-worker processes. Do not put the cloud key in `.env`, scripts, config cache, logs or documentation.

| Variable | Synthetic Gemini demo value |
|---|---|
| APP_ENV | local |
| AI_DRIVER | cloud |
| AI_PIPELINE_ENABLED | true |
| AI_CLOUD_BASE_URL | https://generativelanguage.googleapis.com/v1beta/openai/ |
| AI_CLOUD_MODEL | gemini-3.8-flash |
| AI_CLOUD_API_KEY | Injected by the launcher; never printed or written |
| AI_LEGAL_APPROVAL_REF | SYNTHETIC-DEMO-ONLY |
| QUEUE_CONNECTION | database |

Existing normal application/file/HMAC keys are still required. Existing persisted AI settings override environment defaults: an authorized administrator with `ai.pipeline.manage` must enable the pipeline and set the synthetic approval reference in AI Operations if overrides exist. Existing consent, masking, approval, EGP cap and circuit breaker all still apply. The command refuses an actor without that permission; grant it through authorized user administration first, or pass `--actor=...` for an existing authorized user. Real data needs the lawyer's written Q04 decision. Token rates remain configurable conservative estimates.

## Run

`php artisan recruit:demo-ai-run --work` resets **all synthetic rehearsal review data, dependent files and queued jobs**, through the existing reset action. It preserves users, their MFA enrolments, roles, permissions and settings. It imports 12 raw synthetic DOCX files and records synthetic consent; extraction, evidence verification and rules scoring happen through the normal pipeline. The old offline review seeder's invented field/score rows are not imported. The source texts share the same qualifications, so tied real ranks are legitimate.

`--with-mail` also polls the committed `tests/Fixtures/eml` through a dedicated fake mailbox. The fixture set intentionally includes unreadable and uncertain material. In fake-AI mode, heterogeneous mail CVs use a recorded empty-facts response and remain for human review; the 12 main DOCX files use the evidence-bearing recording. No live mailbox or SMTP transport is configured by this command.

Without `--work`, leave the launcher's workers running: `php artisan queue:work database --queue=extraction,scoring,intake,default` and `php artisan queue:work ai --queue=ai`. Both must inherit the same cloud environment. Long-running workers must be restarted after environment changes. `php artisan recruit:dispatch-ai` uses existing recovery when prerequisites become available.

`php artisan recruit:demo-ai-run --summary` reads current counts without resetting. It reports documents, pending text extractions, AI runs/successes, scores, pending AI work, and whitelisted error/defer codes. Error counts count records and can include the same incident at multiple stages. A queued/deferred snapshot is not completion. `--summary --work` drains already queued work without resetting.

For offline use, change only `AI_DRIVER=fake` (keep explicit pipeline enablement). The driver selects recordings only for the marked synthetic demo, never as cloud failover. Tests always block external HTTP.

## Mailbox UI

Owner/operator users with `mailboxes.manage` can create and edit recruitment mailboxes, save encrypted write-only password/OAuth fields, test authentication and folder access without reading messages, and queue a poll. Blank credential fields keep the saved value; changing provider requires new credentials. The default starts from now. An explicit start-pointer/host/folder/provider/address change resets the cursor so the normal driver can apply that pointer. Existing IMAP polling remains EXAMINE plus BODY.PEEK, with no source-message mutations. Microsoft/Google provider administration and grant configuration still happen in their respective consoles.

## Verification boundary

Offline request tests exercise the Gemini-compatible wire shape, masking, validation and usage/cost reconciliation. They do not establish Gemini account access or live model quality. A live run requires a process-injected key; never replace failed cloud requests with fake results.


## Rescore stored extractions without AI

After applying the additive `2026_10_18_130000_add_criterion_normalizations` migration to the intended demo database (`php artisan migrate`, never `migrate:fresh` there), run:

```powershell
php artisan recruit:demo-ai-run --rescore
```

The command is local only and uses the existing actor (`--actor=owner@example.test` by default), AI-operation permission and review/job scope. It evaluates marked demo applications against each job's current criteria version using current active-document extractions and human corrections. Each explicit request appends score/result rows; old scores and original extraction prompt/model provenance remain. Missing/unusable inputs are skipped or retain the engine's review outcomes. The summary reports newly created/skipped evaluations, current bands and cumulative history counts.

This path does not import/reset data, process queues or dispatch AI explanations. Do not combine it with `--work` or `--with-mail`. No process cloud key is needed for rescoring. Normalization only changes comparison values; encrypted mappings are shown alongside criteria in detail/history views, while verified quotes and offsets remain unchanged. Unmapped text stays raw. Synonym settings remain approval-snapshotted: extend the dictionary and approve a new criteria version to apply changed aliases. New extractions use prompt `extract-v2`; v1 is kept for provenance.

The implementation/gates did not run this command against `database/database.sqlite`; its live demo and MFA were left untouched. Rescore validation uses isolated synthetic data only.
