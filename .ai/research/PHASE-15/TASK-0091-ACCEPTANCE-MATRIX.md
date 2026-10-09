# TASK-0091 — Offline Canary, Holdout, Outcome, Rollback Acceptance Matrix

Task: `TASK-0091` — Implement offline canaries, holdouts, promotion thresholds and rollback.

Status: **PENDING** PR #543 exact-head and resulting protected-main certification. This is a proposed acceptance matrix, not the completion transition.

## Evidence by acceptance criterion

| Criterion | Implementation/evidence | Required terminal validation |
| --- | --- | --- |
| AC-1 Frozen, consent-safe assignments and valid denominators | #541: `BoundedAutonomyOfflineCanaryReview` independently sourced frozen cohort, holdout, nonzero minimum, quarantine/crossover denial, assignment SRM. #542: immutable, current-authorization checked one-per-experiment PostgreSQL cohort receipt; tampered manifest/denominator and revoked approver tests | Exact-head #543 and resulting main, including PG integration |
| AC-2 Deterministic external scoring and low-trust denial | #541: `BoundedAutonomyOfflineCanaryScore` independent source, prespecified analysis/horizon/Bonferroni, positive conservative interval and practical effect; quarantined, low-trust and unverifiable sources hold. #543: typed cohort receipt-to-outcome join requires matching plan, analysis, assignment manifest, outcome source manifest, current scope and no duplicate/crossover, with negative fixtures | Exact-head #543 and resulting main; promotion execution remains disabled |
| AC-3 Conservative rollback/replay/reconciliation | #541: `BoundedAutonomyOfflineRollbackReview` distinguishes unknown, late, duplicate, verified-not-applied, verified-applied, nonzero cost and irreversible effects; never fabricates compensation or calls a provider. #542 ensures previously recorded evidence remains immutable after changed or revoked approval | Exact-head #543 and resulting main |

## Model / provider boundary

- Default `DenyingBoundedAutonomyCanaryCohortSource`, `DenyingBoundedAutonomyCanaryOutcomeSource`, `DenyingBoundedAutonomyCanaryOutcomeJoinSource` and rollback source give **no positive production provider evidence**.
- An interface's `verified` flag is not an independent cryptographic signature or a substitute for connected provider verification. Positive fixtures test only deterministic gates.
- No recipients are enrolled, no communications sent, no ads purchased, no automatic winners published, no billing, no actual rollback, no refund and no production causal effect claimed.
- `promotion_authorized=false` and `execution_authorized=false` in all offline outcomes. TASK-0091 task acceptance would certify the **offline policy boundary and negative fixtures**, not provider readiness.
- TASK-0092 red-team/incident monitoring must not be activated until TASK-0091 is marked completed by the canonical task transition with verified evidence.

## Required transition

Only after PR #543 passes Foundation, PHP floor, PostgreSQL integration, E2E, Security and Governance on its exact immutable head, merges, and resulting main gates pass: mark TASK-0091 AC-1/AC-2/AC-3 done, recompute weighted progress from task weights, append journal/checkpoint and README progressbar, transition successor TASK-0092 to READY. Otherwise retain TASK-0091 IN_PROGRESS.
