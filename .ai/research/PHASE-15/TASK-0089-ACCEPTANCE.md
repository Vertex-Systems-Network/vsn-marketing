# TASK-0089 Bounded Offline Autonomy Acceptance Evidence

Task: TASK-0089 — Implement typed bounded goal-to-observe autonomous loop
Phase: PHASE-15
Status: **verification pending resulting-main CI** (this draft is not certification by itself)

## Certified exact-head delivery carriers

| PR | Resulting main | Evidence |
| --- | --- | --- |
| #525 | `c5027d289c6659bc466fcb339d136618339a2a4f` | Tenant-/actor-bound durable offline proposal receipts, idempotency, conflicts and adversarial replay |
| #526 | `b8a8993abcb077fc838a4a875c9c2ddd7dec42b5` | Independently sourced observation contract; missing evidence held and verified aggregate evaluation cannot self-promote |
| #528 | `c01c7554afde3db0dffc6572626e12ddb41146ec` | Permission-checked server-issued analytics preview, current tenant/evidence/purpose revalidation, accessible operator form |
| #529 | `7432dbfd3b3a6d30d5509875a4c4ae655c2cbdbe` | Monotonic typed offline lifecycle guards, persistent operator receipts and adversarial transitions |

PR #529 verified exact head `3fe2d9052f2589e7886061c0610734016b5c100d`: Application Foundation (backend, architecture, static analysis, Laravel Pint, frontend build), PHP 8.3 floor, PostgreSQL integration, E2E, Security Supply Chain and AI Continuity/Governance all passed before merging to protected main. The formatter failure on an earlier head was corrected; it is not part of the certified head.

## Acceptance case matrix

| Criterion | Implementation and negative evidence | Remaining terminal prerequisite |
| --- | --- | --- |
| AC-1: typed tenant-scoped goal/tool/state machine | `BoundedAutonomyPreview`, `BoundedAutonomyOfflineLifecycle` and tests reject foreign actor/tenant, unregistered tool, send/publish, action injection, stale/retrograde/skipped state and escalated policy | Final resulting-main CI |
| AC-2: durable idempotent replay / failure isolation | `BoundedAutonomyOfflineReceipt`, `BoundedAutonomyOfflineEvaluation` with `IdempotentExecutor`; unit plus PostgreSQL replay/concurrency/conflicting-input tests; operator proposal produces a durable receipt | Final resulting-main CI |
| AC-3: accessible safe preview, explainable observation and prohibited actions | `OfflineAutonomyPreviewPanel` and React tests, analytics operator feature tests, held/unverified fact tests, verified aggregate review-only decisions; send/promote controls disabled | Final resulting-main CI |

The default `DenyingBoundedAutonomyObservationSource` yields no positive production evidence. Offline `execute` is always disabled. No real message delivery, ad purchase, social publishing, auto-promotion, external provider activation, billing transaction, new secret or production deployment is authorized or claimed. A threshold being met is operator review evidence, **not causal uplift**.

## Evidence gating and successor

Check resulting protected `main` `7432dbfd3b3a6d30d5509875a4c4ae655c2cbdbe` for the required Application/Foundation, PHP floor, PostgreSQL integration, E2E, Security and governance workflow results. **Only after those are green**, mark TASK-0089 AC-1..3 completed in canonical task/index, recompute progress deterministically, reconcile journal/checkpoint/README, and activate TASK-0090 as the next dependency-ready task. TASK-0090 must preserve the offline-only default until its own budget, approval and kill-switch gates are certified.
