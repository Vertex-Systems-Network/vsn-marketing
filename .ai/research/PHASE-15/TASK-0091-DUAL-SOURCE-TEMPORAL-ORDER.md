# TASK-0091 — Causal Ordering Across Independent Provider Sources

Status: **offline-only, staged candidate; not certified**.

## Evidence inversion

A verified aggregate `confirmed_not_applied` result can be timestamped *before* a later provider attempt included in an independently verified per-attempt manifest. Both sources can individually satisfy their one-hour review freshness limits, but their joint positive reconciliation would falsely imply that the earlier aggregate observation covers the later attempt.

## Fail-closed guard

A positive per-attempt review now exposes the maximum attempted-at timestamp from its manifest; a positive aggregate review exposes its independently observed-at timestamp. The joint review refuses to call the evidence an offline review candidate if the aggregate observation precedes that latest attempt or either temporal value is unavailable or incorrectly typed.

The existing exact run/snapshot, tenant/actor, expected operations, idempotency, no-effect, independent-source, freshness, cost and callback gates still apply. Chronology is necessary but not sufficient evidence of provider non-effect. The source still defaults to deny; local digests are not provider signatures; no actual rollback/refund/retry/send/expense/promotion is authorized.

## Adversarial coverage

A test uses an aggregate observed at evaluation -100s and a provider attempt at evaluation -50s (both individually fresh, aggregate's own attempted_at at evaluation -200s) with a recomputed valid attempt manifest digest. It must be **held_offline** with `aggregate_review_predates_provider_attempt`, and all external action flags false.

TASK-0091 AC-1..3 remain incomplete until required exact-head and protected-main gates, holistic independent-source review, and canonical guarded acceptance/transition.
