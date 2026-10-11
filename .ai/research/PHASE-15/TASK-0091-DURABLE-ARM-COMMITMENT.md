# TASK-0091 — Durable Frozen Per-Arm Assignment Commitment

Status: **offline-only staged candidate; required exact-head/protected-main checks not yet certified**.

## Defect closed

A frozen-cohort receipt retained only a manifest digest, total assigned population and holdout assigned count. Even after PRs #556–#558 introduced independent per-arm readback, two separate source adapters could agree on a changed control/treatment allocation that preserved totals and re-used the same opaque, unverified manifest identifier. The durable receipt itself could not detect that change.

## New immutable evidence

An additive, nullable `assignment_arm_set_sha256` column stores a canonical SHA-256 commitment to **the frozen plan hash and the sorted complete per-arm assigned vector**. The new pure `BoundedAutonomyArmEvidenceDigest` validates typed, bounded counts and derives the same checksum regardless of map ordering, while rejecting a changed allocation or plan.

Receipt creation writes this hash atomically beside the frozen source manifest and denominators. Receipt replay requires exact equality with the recorded hash, so an otherwise same-total/same-holdout reallocation cannot overwrite or re-confirm the original. A positive independent outcome join must first find a valid persisted commitment and then independently recompute it from the newest validated frozen-cohort readback. Changed/missing commitments fail closed; no external action is authorized.

## Migration and legacy safety

- Migration `2026_10_11_000001_add_canary_assignment_arm_commitment.php` adds **only one nullable field**. No table recreation, backfill, deletion, default synthetic signature, live source activation, or new provider credential
- Old receipt rows remain byte-for-byte intact, with null commitment. A null legacy commitment cannot be silently inferred from incomplete old totals and holds any otherwise-positive offline review
- Re-entrant `up()` is safe when the new field already exists
- `down()` refuses to discard the field while **any** immutable canary review receipt exists, requiring explicit archive/restore review before destructive rollback
- Unit and SQLite feature fixtures test canonical hash ordering, changed same-total allocation, tampered/legacy receipt refusal, replay integrity and guarded migration rollback. PostgreSQL integration and exact-head CI are required before promotion to `main`

## Migration release/recovery review

| Dimension | Review result |
| --- | --- |
| Idempotency | The migration checks the existing table and column; rerunning an already-applied schema step is a no-op |
| Transactions | Single additive nullable schema change, with no data writes or assumption that provider-specific DDL is transactionally reversible |
| Apply-marker recovery | Column-created/ledger-not-recorded state can be retried without re-adding a column |
| Retry | Existing column returns safely; invalid missing parent table fails without creating synthetic evidence |
| Rollback and restore | An empty receipt table permits column removal; any retained immutable evidence blocks `down()` until a separate approved export/restore plan |
| Destructive recovery | No destructive `up()`; deletion of evidence is explicitly out of scope |
| Concurrency | Deployment must use one serialized migration runner; duplicate concurrent DDL fails closed and must be retried under that runner |
| Partial execution | New column has no data backfill; interrupted apply preserves existing rows and may re-enter `up()` |
| Backup and snapshot | No backup is taken here and no production migration is executed; verify a current backup/snapshot before a later authorized production apply |

This is a code-review signoff on the nine dimensions, **not** proof that any production backup has been taken, production migration applied, or live database restore tested.

## Limitations

This is an internal tamper-evident checksum, **not a digital signature, independent provider attestation, proof of current consent, causal uplift, or production rollback**. Trustworthy independent sources are still deny-by-default unless separately configured with validated provenance and current authority. Live sending, ad spend, publication, refunds, retries and automatic promotion remain forbidden. TASK-0091 acceptance criteria remain NOT DONE pending holistic evidence and guarded transition.
