# TASK-0091 Canonical Operation Coverage (Offline-Only)

Status: PR #552 staged, **not** certified or promoted. No external side effect.

## Threat model

A provider attempt source may claim a complete list yet omit a planned operation or substitute an identically sized record. Trusting a provider `complete=true` or matching only counts may incorrectly describe uncertain irreversible external outcomes as a safe rollback.

## Three independent inputs

1. Aggregate verified provider non-effect review.
2. Independently verified per-attempt receipt/idempotency/outcome manifest, with sorted operation identity digest.
3. Authenticated canonical expected operation inventory originating from durable server-owned run data, not provider callbacks or AI output. The default inventory adapter is a denying source.

`BoundedAutonomyOfflineOperationCoverageReview` holds when any source is unavailable, incomplete, stale, duplicated, tampered, foreign-tenant or mismatched by exact operation + idempotency key, even when the number of attempts is identical. It returns a human-only offline review candidate if and only if all three sources agree.

## Certification boundary

No real expected-operation source or provider attestation is configured. These local digests are integrity checks, not provider signatures or deployment authorization. `rollback_performed`, `refund_authorized`, `retry_authorized`, `execution_authorized` and `promotion_authorized` are all permanently false in these read-only review paths.

TASK-0091 AC-1..3 remain **in progress** pending independent authoritative adapters, holistic evidence reconciliation and exact-head/resulting-main controls.
