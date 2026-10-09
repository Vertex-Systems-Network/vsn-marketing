# TASK-0091 — Offline Canary / Holdout / Rollback Acceptance Matrix

Status: **in progress, not certified**. The matrix distinguishes executable offline contracts and synthetic tests from unavailable live external-provider proof. No owner approval or provider activation is inferred.

## AC-1: Immutable assignment, consent and denominator safety

Evidence carriers: PR #541 independent frozen-cohort review and ExperimentStatistics; PR #542 durable, tenant/actor-bound cohort receipts; PR #543 canonical frozen-cohort receipt to independently sourced outcome manifest. Certified lower-level implementation includes `ExperimentAssignments`, `BoundedAutonomyOfflineCanaryReview`, `DatabaseBoundedAutonomyCanaryReviewReceipt` and `BoundedAutonomyOfflineOutcomeJoinReview`.

Negative obligations: cross-workspace/brand or stale provenance, active-source absence, unconsented/unfrozen cohorts, exposed holdouts, sample-ratio mismatch, poisoned outcome joins, untrusted denominator and replay must hold. Real outcome attestation adapters remain deny-by-default.

## AC-2: Independent, deterministic scoring and human promotion

Evidence carriers: PRs #543, #546-#550. `BoundedAutonomyOfflineCanaryScore` delegates fixed-horizon and multiplicity-aware statistics to `ExperimentStatistics`; independently sourced outcome and current consent/permission/human decision are required. Operator review never means causal uplift is proven or a variant is automatically promoted.

Negative obligations: positive AI narrative alone, low-trust/duplicate events, self-approval, revoked permissions, changed immutable plan/actor/tenant, conflicting human approval replay and tampered historical decision cannot promote.

## AC-3: Provider outcome reconciliation and irreversible-effect honesty

Evidence carriers: PRs #544-#545 durable append-only offline rollback history and concurrency; PR #551 independent per-provider attempt readback combined with aggregate outcome; PR #552 independently sourced expected canonical operations set (pending exact-head certification). Follow-up stale-provider timestamp bounds are staged, not certified.

Negative obligations: missing/fake provider source, unknown/late/duplicate/irreversible/costly attempt, insufficient attempt inventory, same-count different operation IDs, changed idempotency, stale timestamps and conflicting history must not claim rollback performed, refund/retry permission or verified no effect.

## Completion / scope

Each criterion remains **NOT DONE** until its current final required exact-head and protected-main gates and task transition pass. Positive unit fixtures certify deterministic OFFLINE logic only, not external provider signature, live send, production rollback, promotion, billing or permission to execute. Those effectful authorities remain disabled. Follow-up TASK-0092 red-team task cannot activate until TASK-0091 is formally accepted in canonical state.
