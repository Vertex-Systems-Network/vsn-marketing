# TASK-0091 — Bind Canonical Operation Inventory Completeness to Its Manifest

Status: **independent staged AC-3 hardening, not yet certified**.

## Observed integrity gap

The expected canonical operation source previously returned an `operation_set_sha256` containing only sorted operation identities. The source envelope also declared `complete`, observation time and expiry, but those fields were *not* committed to any checksum. An unchanged operation-set hash could accompany `complete: false` on one read and `complete: true` on a later read, or a changed observation timestamp, without being detected as modified provenance.

## Offline correction

A new `inventory_manifest_sha256` is required in addition to the existing exact operation-set checksum. The pure `BoundedAutonomyOperationInventoryDigest` commits to tenant scope, immutable run and snapshot, observation/expiry timestamps, completeness claim, exact submitted operation list and operation-set digest. After validating operation identity/type/schema and recomputing the exact sorted set, `BoundedAutonomyOfflineOperationCoverageReview` recomputes and verifies the full envelope checksum before considering any offline complete non-effect candidate.

Tests preserve a legitimate complete list as an offline-only candidate, preserve incomplete lists as held, and reject a `false → true` completeness flip or changed observation time under the original digest. Previous duplicate, same-count-but-different-ID, unknown provider and untrusted scope checks still apply.

## Boundary

This checksum is **not** an independent provider signature. There is no live canonical-operation adapter or external attestation activated by this change. A valid internal hash never proves provider non-effect, grants rollback/refund/retry, or authorizes execution, spending, publication or auto-promotion. TASK-0091 AC-3 remains **NOT DONE** pending complete independently trustworthy evidence and formal guarded acceptance.
