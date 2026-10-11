# TASK-0091 — Offline Canary / Holdout / Rollback Acceptance Matrix

Status: **in progress, not certified**. The matrix distinguishes executable offline contracts and synthetic tests from unavailable live external-provider proof. No owner approval or provider activation is inferred.

## AC-1: Immutable assignment, consent and denominator safety

Evidence carriers: PR #541 independent frozen-cohort review and ExperimentStatistics; PR #542 durable, tenant/actor-bound cohort receipts; PR #543 canonical frozen-cohort receipt to independently sourced outcome manifest. Certified lower-level implementation includes `ExperimentAssignments`, `BoundedAutonomyOfflineCanaryReview`, `DatabaseBoundedAutonomyCanaryReviewReceipt` and `BoundedAutonomyOfflineOutcomeJoinReview`.

Negative obligations: cross-workspace/brand or stale provenance, active-source absence, unconsented/unfrozen cohorts, exposed holdouts, sample-ratio mismatch, poisoned outcome joins, untrusted denominator and replay must hold. PR #556 merged and exact-head certified strict total/holdout comparison; PR #557 merged and exact-head certified independent frozen per-arm assignment readback for positive operator candidates. This follow-up stages per-arm exposure parity and source observation ordering; immutable database receipt still stores only total and holdout, and live source signatures remain default-deny/unverified. Real outcome attestation adapters remain deny-by-default.

## AC-2: Independent, deterministic scoring and human promotion

Evidence carriers: PRs #543, #546-#550. `BoundedAutonomyOfflineCanaryScore` delegates fixed-horizon and multiplicity-aware statistics to `ExperimentStatistics`; independently sourced outcome and current consent/permission/human decision are required. Operator review never means causal uplift is proven or a variant is automatically promoted.

Negative obligations: positive AI narrative alone, low-trust/duplicate events, self-approval, revoked permissions, changed immutable plan/actor/tenant, conflicting human approval replay and tampered historical decision cannot promote.

## AC-3: Provider outcome reconciliation and irreversible-effect honesty

Evidence carriers: PRs #544-#545 durable append-only offline rollback history and concurrency; PR #551 independent per-provider attempt readback combined with aggregate outcome; PR #552 independently sourced expected canonical operations set (merged with full exact-head gates); PR #553 relative-to-capture/observation timestamp bounds (merged, resulting-main CI verification separate). PR #554 merged and exact-head certified evaluation-clock absolute freshness; PR #555 merged and exact-head certified aggregate-versus-attempt chronology. These are offline review invariants, not independent proof of actual provider non-effect.

Negative obligations: missing/fake provider source, unknown/late/duplicate/irreversible/costly attempt, insufficient attempt inventory, same-count different operation IDs, changed idempotency, stale timestamps, aggregate non-effect observations that precede a later provider attempt and conflicting history must not claim rollback performed, refund/retry permission or verified no effect. The independent cross-source chronology hardening was merged in PR #555; resulting-main verification is tracked separately and no execution permission is inferred.

## Completion / scope

Each criterion remains **NOT DONE** until its current final required exact-head and protected-main gates and task transition pass. Positive unit fixtures certify deterministic OFFLINE logic only, not external provider signature, live send, production rollback, promotion, billing or permission to execute. Those effectful authorities remain disabled. Follow-up TASK-0092 red-team task cannot activate until TASK-0091 is formally accepted in canonical state.
