# PHASE-15 TASK-0091 — Offline Canary / Rollback Acceptance Matrix

**Status:** Evidence draft, pending exact-head and resulting-main certification of PR #545. This document does **not** certify live marketing activation, conversion accuracy, provider outcome authenticity, or automatic promotion.

| Requirement | Offline-only implementation evidence | Negative / fail-closed boundary |
| --- | --- | --- |
| AC-1: Frozen consent-safe canary, holdout and valid denominators | PR #541 `BoundedAutonomyOfflineCanaryReview` checks immutable cohort, eligible/assigned/exposed denominators and sample ratio mismatch. PR #542 `DatabaseBoundedAutonomyCanaryReviewReceipt` persists scope-bound immutable evidence. | Missing independently verified assignment source, revoked operator permission, exposed holdout, crossovers, quarantine, changed plan, actor/tenant replay and insufficient cohort must deny |
| AC-2: External deterministic scoring prevents self-promotion | PR #541 `BoundedAutonomyOfflineCanaryScore` delegates to fixed-horizon multiplicity-aware `ExperimentStatistics` with predefined uplift and confidence; PR #543 `BoundedAutonomyOfflineOutcomeJoinReview` binds current canonical experiment, receipt, analysis and independent outcome manifest. | Source interfaces default to denying, no verified conversion adapter is claimed, stale/forged/mismatched evidence holds; no score promotes a strategy or creates an external action |
| AC-3: Rollback reconciles late, duplicate, irreversible outcome uncertainty | PR #541 `BoundedAutonomyOfflineRollbackReview` never treats unknown, late, duplicate, applied or charged effects as successful rollback. PR #544 `DatabaseBoundedAutonomyOfflineRollbackReviewEvent` persists append-only per-run decision history with actor/tenant and idempotent fingerprints. PR #545 adds PostgreSQL competing-worker replay certification. | Unknown source stays held; later independently reviewed non-effect cannot erase prior uncertainty; no refund, provider cancellation, resend, rollback success, spending or promotion is ever claimed |

## Evidence to verify before transition

1. PR #545 exact head Foundation/backend, PHP floor, PostgreSQL infrastructure contention, E2E, Security and AI Continuity/Governance checks all PASS; merge verified head into protected main.
2. Protected main checks for resulting SHA PASS; no repository reconciliation drift or ambiguous ownership of the active PR/queue.
3. Mark canonical AC flags complete only for **offline policy and evidence processing**. Explicitly distinguish tested synthetic fixtures from independently verified real provider results.
4. README dashboard percentages must be derived from canonical task weights and match checkpoint/append-only journal. Do not activate TASK-0092 until TASK-0091 is canonically accepted.

## Production/live evidence explicitly NOT supplied

- Positive independently authenticated provider outcome adapter, production conversion provenance and verified marketing uplift.
- Operator authorization to launch canaries, issue provider calls, refund billing, re-send messages or automatically promote winners.
- Any production rollout, customer enrollment or real-world rollback.

These remain disabled. If these are mandatory for a future live feature, they require separate authorization and independent testing; the offline milestone cannot be represented as live-ready.
