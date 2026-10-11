# TASK-0091 — Provider Attempt Evaluation-Clock Freshness (Offline Only)

Status: **staged candidate; not certified**. This change has no provider adapter, external action, refund, send or promotion authority.

## Gap closed

PR #553 rejects attempts older than one hour relative to their provider capture/aggregate observation. However, a capture or observation that was itself up to one hour old could still include an attempt nearly two hours old. Source-relative timestamp freshness is not sufficient for an evaluation at `$at`.

## Bounded policy

Both independent sources now require each `attempted_at_unix` to be within **3600 seconds of the current evaluation clock**, in addition to the existing capture/observation bounds. Preserve explicit rejection of future, non-integer, stale, tampered and unexpectedly shaped records. No outcome is inferred from a callback's `late` claim.

Adversarial regression fixtures exercise a source captured/observed 3300 seconds before evaluation with an attempt 4000 seconds before evaluation (rejected), and the exact 3600-second evaluation boundary (offline review candidate only). Source manifests remain integrity checks, not signatures; source adapters default deny.

## Remaining TASK-0091 gates

AC-1, AC-2 and AC-3 are **not done** until holistic independent-source acceptance, full exact-head CI and protected-main evidence, followed by the guarded canonical task transition. Passing these synthetic unit tests must not be represented as real provider reconciliation or permission to execute/retry/refund/promote.
