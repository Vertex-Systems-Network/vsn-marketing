# TASK-0092 — Offline Red-Team and Incident Monitoring Certification

Status: **local-ready; protected PR and resulting-main gates pending**. Task and phase acceptance flags remain false.

## Acceptance evidence

| Criterion | Reproducible boundary | Scope limit |
| --- | --- | --- |
| AC-1 | The adversarial unit and feature tests in [TASK-0092-ACCEPTANCE-MATRIX.md](TASK-0092-ACCEPTANCE-MATRIX.md) execute goal hijack, untrusted context, tool escalation, model-stated approval, foreign scope, agent collusion and exfiltration attempts. Runtime invocation counts and withheld outputs check that failed proposals do not advance authority. | The synthetic envelope's category labels do not attest to those test runs. No real external attack or provider identity is certified. |
| AC-2 | PostgreSQL multi-worker quota/stop, receipt, rollback and tool-guard tests exercise contention and replay. A simulated provider/queue exception fails closed; safe same-key redelivery returns the completed receipt, while uncertain rollback holds equivalent writes. | A stub is not remote settlement or transport telemetry. Unknown external effects remain held for independent review. |
| AC-3 | A bounded, tenant-scoped audit and operator page expose redacted counts; a separate human actor may record append-only investigating/escalated acknowledgement. Source references, chain, coverage and monotonic escalation are checked. The recovery review is explicitly `blocked_unverified_external_effect`. | Neither triage nor a synthetic record means production incident resolution or performed recovery. Live send, publish, spend, retry, promotion and rollback authority remain disabled. |

## Gate sequence

Local PHP 8.3, PostgreSQL, browser E2E, frontend and governance evidence is recorded in the acceptance matrix. Submit this carrier with `CI-Mode: full`; verify the exact PR head passes Application Foundation, PHP 8.3, PostgreSQL, browser E2E, Security and AI Governance. Merge only that green head, verify the resulting protected main, then use `tools/ai_txn.py transition` to record AC-1/2/3 and activate TASK-0093. TASK-0093 must independently certify the whole PHASE-15 task chain and retain the offline-only authority boundary. Do not activate PHASE-16.
