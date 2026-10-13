# TASK-0092 — Local Adversarial Evidence Matrix

Status: **local offline evidence verified; exact-head and resulting-main certification pending**. This is a reproducibility inventory, not production incident certification.

## Registered attack classes

| Class | Executed negative fixture | Observed boundary |
| --- | --- | --- |
| `goal_hijack` | `BoundedAutonomyRedTeamBoundaryTest` | Goal purpose, policy and oversized target changes reject. |
| `tool_misuse` | `BoundedAutonomyRedTeamBoundaryTest`, `Task0092TypedToolFailureRedTeamTest` | Unregistered send tool and forged write effect reject; failed reversible write holds. |
| `self_approval` | `BoundedAutonomyOfflineApprovalReviewTest`, `Task0092TypedToolFailureRedTeamTest` | Model-stated approval does not satisfy independently checked reference. |
| `cross_tenant` | `BoundedAutonomyRedTeamBoundaryTest`, `AiAgentRuntimeTest`, `DatabaseBoundedAutonomyOfflineRedTeamAuditTest` | Foreign workspace/brand/context and monitor read reject. |
| `quota_race` | `AutonomyOfflineMultiWorkerQuotaStressPostgresTest` | Four independent claims yield exactly one offline reservation. |
| `emergency_stop` | `AutonomyOfflineStopAdmissionRacePostgresTest` | Stop wins competing admission without consuming quota. |
| `provider_replay` | `BoundedAutonomyReceiptPostgresTest`, `AutonomyRollbackLedgerContentionPostgresTest`, `Task0092TypedToolFailureRedTeamTest` | Conflicting receipt and unknown rollback deny; safe same-key completion replays. |
| `metric_spoofing` | `BoundedAutonomyOfflineEvaluationTest`, `BoundedAutonomyRedTeamBoundaryTest` | Untrusted metric/source or malformed measured evidence denies evaluation. |
| `exfiltration` | `AiAgentRuntimeTest`, `Task0092TypedToolFailureRedTeamTest` | Private marker, URL and fake credential text withheld from output/audit. |

The nine labels in a signed synthetic envelope are **not** linked automatically to these fixtures. The table ties each claim to an actual test path and limited observed boundary; it cannot prove an external attack was blocked.

| Criterion | Current direct fixture | Boundary shown | Remaining acceptance work |
| --- | --- | --- | --- |
| AC-1 goal hijack, untrusted data, tool misuse, tenant/exfiltration | `BoundedAutonomyRedTeamBoundaryTest`, `AiAgentRuntimeTest`, `BoundedAutonomyOfflineApprovalReviewTest`, `BoundedAutonomyOfflineRedTeamIncidentReviewTest` | Executed offline fixtures deny unregistered fields/tools, foreign tenant/brand/source and model-stated approval, withhold private markers, stop a forged send before the next specialist and isolate text instructions from its context. The nine labels in a synthetic envelope are checked for coverage but are not treated as executed tests. | External prompt/tool source attribution and real provider behavior are not certified. |
| AC-2 quota, stop, replay, provider/queue failure | `AutonomyOfflineMultiWorkerQuotaStressPostgresTest`, `AutonomyOfflineStopAdmissionRacePostgresTest`, `BoundedAutonomyReceiptPostgresTest`, `AutonomyRollbackLedgerContentionPostgresTest`, `AutonomyToolGuardContentionPostgresTest`, `Task0092TypedToolFailureRedTeamTest` | Independent workers serialize quota and stop, duplicate receipt/rollback reads fail closed, equivalent writes cannot overtake failed rollback, simulated provider/queue exceptions redact and hold, and same-key safe redelivery replays a receipt without another handler call. | Partial remote outcome and end-to-end queue transport attribution are outside the offline fixture. No real provider effect follows from the stubs. |
| AC-3 monitoring, audit, recovery | `DatabaseBoundedAutonomyOfflineRedTeamAuditTest`, `offline-incident.test.tsx`, `DatabaseBoundedAutonomyOfflineRedTeamMonitor`, `DatabaseOfflineRedTeamTriage` | Append-only synthetic audit, count-only tenant-scoped read and operator page, independent investigating/escalated acknowledgement, source provenance and monotonic escalation validation, and an explicit `blocked_unverified_external_effect` recovery review. | Acknowledgement does not resolve an incident or perform recovery. Independent production telemetry and human recovery adjudication are unavailable; the hold remains. |

## Current-head local checks (2026-10-13)

- PHP 8.3 full suite: **1072 passed, 154 infrastructure skips, 7235 assertions**. Focused adversarial/audit suite: **29 passed, 319 assertions**.
- All seven PHASE-15 PostgreSQL integration files: **8 passed, 102 assertions**. Audit and typed-tool features on PostgreSQL: **16 passed, 233 assertions**.
- Chromium browser E2E with persistent SQLite: **7 passed**. The exact Chrome for Testing headless archive was obtained from the official distribution after the Playwright CDN returned a corrupt archive; this local test does not substitute for CI.
- Frontend: **47 passed**; TypeScript typecheck and Vite build passed. Focused PHPStan and Pint passed. AI continuity, policy, Supervisor, parallel, Runner Benchmark, context, workstream-ledger, action-pin validations and negative suites passed.

The latest triage regression denies an investigating downgrade even after a new source audit, rejects a forged source ID despite a recomputed local fingerprint, and rejects a persisted ready count when exfiltration coverage is zero. These are local checks of offline contracts. TASK-0092 acceptance flags remain false until exact-head PR and resulting-main gates pass; TASK-0093 phase certification is separate.
