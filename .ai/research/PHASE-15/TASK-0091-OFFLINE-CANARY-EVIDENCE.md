# TASK-0091 Offline Canary/Score/Rollback Implementation

Status: IN PROGRESS. PR #541 contains *offline-only* typed read-only review contracts and adversarial fixtures. No production experiment launch.

## Scope of first carrier

- Existing Experiments module owns real frozen assignment, eligibility and consent gates. The new independent cohort source defaults to deny; reviewer rejects unfrozen, cross-tenant, unconsented, exposed holdout, insufficient denominator, quarantined and SRM-corrupted cohorts.
- Independent outcome source defaults to deny; Phase-11 fixed-horizon ExperimentStatistics supplies multiplicity-aware intervals. Low-trust events cannot become promotion evidence. Review requires all treatment arms have positive lower-bound effect and at least five percentage-point observed difference; AI does not select a cherry-picked winning variant.
- Rollback source defaults to deny. Unknown, late, duplicate, confirmed-applied or nonzero-cost outcomes hold; no provider refund, re-send, false rollback or external success is ever inferred.

## Deferred acceptance requirements

- Bind actual trustworthy cohort/outcome adapters or independently signed fixtures with provenance, anti-duplication and current consent; reproduce end-to-end denominator joins.
- Persist a versioned offline promotion/rollback decision ledger, bound to independent thresholds and kill switches. Build failure and concurrency tests for replayed provider callbacks.
- Exact-head and resulting-main CI to pass before any TASK-0091 acceptance. TASK-0092 must not activate early.

No real campaign send, ad spend, third-party publication, billing, live provider enrollment, automatic promotion or refund authority.
