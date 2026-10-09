# TASK-0091 — Bounded Offline Canary / Rollback Acceptance Matrix

**Status: DRAFT — NOT CERTIFIED.** Do not mark TASK-0091 complete or activate TASK-0092 until PR #551 has fully passed exact-head gates, merged and passed protected-main continuity/application/infrastructure/security controls. The scope is offline-only; live marketing enrollment, provider promotion, money movement and actual external rollback remain disabled.

## Canonical acceptance checkpoints

| Criterion | Existing implemented/tested slices | Terminal requirements |
| --- | --- | --- |
| AC-1 Frozen consent-safe cohort and valid denominators | PR #541 immutable controlled-cohort/holdout and independent scorer; PR #542 durable tenant-bound read-only assignment receipt; PR #543 receipt-to-independent outcome manifest join and contamination/duplicate/crossover holds | Inspect exact frozen run and independent quality tests; certify current protected main |
| AC-2 Independent scoring and no agent self-promotion | PRs #541/#543 reject model-only signals; PR #546 human gate; #547/#548 current authority and server-authenticated decision proof; #549 current-human idempotent replay with stop/permission rechecks; #550 historical tamper-evidence guard | Ensure every apparent positive is **operator review only**; no production promotion/sending |
| AC-3 Conservative rollback and irreversible provider evidence | PR #544 immutable outcome-review event journal; PR #545 PostgreSQL same-run replay isolation; PR #551 per-provider bounded immutable attempt manifests and dual-source independent offline rollback review, including partial/unknown/late/duplicate/applied/costly holds | PR #551 exact-head tests and protected-main certification; no false rollback/refund/retry claims |

## Never-inferred facts

- Offline synthetic fixtures do not prove that actual providers were contacted or that measured campaign lift is causal.
- Default provider-attempt source denies. An independent provider-authorized adapter, external attestation, current owner approval and last-action rechecks require their own policy, implementation and test evidence.
- The local SHA-256 manifest detects internal mutation of a source-provided record but is not an external signature or proof of provider identity.
- Historical approval evidence integrity does not grant the running agent any additional permission.
- AI/canary/rollback outputs always retain `execution_authorized=false`, `promotion_authorized=false`; reviewed non-effects do not authorize provider retries or billing reversals.

## Exact next action

Validate PR #551 current immutable head with full required Foundation, PostgreSQL, PHP floor, E2E, Security and Governance checks; repair failures and merge only verified head. Revalidate resulting protected main and independent adversarial fixtures before marking AC-1/2/3 done, recomputing weighted roadmap progress, synchronizing README/journal and activating TASK-0092.
