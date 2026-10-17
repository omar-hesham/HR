# Go-live checklist — DEL-P0-03

Run `php artisan recruit:go-live-check` (or `--json`). Exit 1 means missing approvals,
configuration or default-policy warnings remain. A successful machine check does not
approve go-live; external acceptance and Omar's explicit deployment authorization remain required.
The command is read-only and never probes a mailbox, SMTP, AI endpoint or server.

| Machine check | Acceptance evidence / owner |
|---|---|
| Seven active jobs, current approved criteria with criteria rows | Factory-approved seven sets; criteria approval reference |
| Five active business accounts, one covering each required role | Approved account roster; operator is separate and time-limited |
| Required panel MFA and enrollment | User MFA enrollment; recovery procedure |
| Active real mailbox, start pointer, cursor, success within 15 min and no error | Factory synthetic inbox smoke and UIDVALIDITY review |
| AI path decision reference | Written local/cloud/provider/data-flow approval; no model benchmark inferred |
| Approved non-placeholder Arabic/English notice | Factory copy, recipients, approved delivery channel |
| Retention and backup-cycle approval references | Q05 policy; defaults always warned; periods positive |
| Backup configured, fresh, separately published current deletion ledger | Egyptian storage evidence and separate key protection |
| Restore drill within quarter, matching database engine | Signed isolated Egyptian restore report before acceptance and quarterly |
| Alert recipient; support/recovery/approval owners | Tested operational alert delivery and on-call responsibility |
| Arabic guide, runbook, deployment log and rollback procedure | Versioned docs and tested compatible code rollback |

External gates (not certified by the command): Q02/Q03 real jobs and 20-CV sample in Egypt;
Q14 threshold and calibration approval; week-1 effort/visibility measurement; week-3 immutable
approval or rules-only fallback; five acceptance tests on the deployed environment; residency,
TLS, firewall, outbound restrictions, legal/provider approvals and key recovery; training and
synthetic screenshots; named support owner and factory approver; signed minutes.

Do not use migrate:fresh on staging/production with data. Use the Arabic runbook §11.3 release
sequence and compatible code-only rollback to preserve inbound applications. This repository's
local synthetic verification is separate from actual server acceptance.
