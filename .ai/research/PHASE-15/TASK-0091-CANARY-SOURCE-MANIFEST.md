# TASK-0091 — Bind Independent Canary Scoring Data to Source Manifest

Status: **offline-only staged candidate; full exact-head and resulting-main certification required**.

## Failure mode

`BoundedAutonomyOfflineCanaryScore` previously treated any 64-character lowercase hex `source_manifest_sha256` as valid source provenance and `BoundedAutonomyOfflineOutcomeJoinReview` compared it to the same opaque join field. An independently formatted source could change assigned, exposed or converted counts (or observation time) while reusing the same digest. Matching opaque strings does not bind the actual statistical denominators and outcomes.

## New checksum contract

- `BoundedAutonomyCanaryOutcomeEvidenceDigest::sha256` commits to tenant/experiment/plan/analysis identity, observation/expiry timestamps, independent-source flag, all assigned/exposed/outcome counts, low-trust/contamination diagnostics.
- Every per-arm map is canonically sorted by variant name before hashing, so map insertion order alone cannot trigger a false difference.
- `BoundedAutonomyOfflineCanaryScore` now recomputes and constant-time compares the entire source manifest before evaluating any statistical candidate. Replaying a stale digest over changed conversion, exposure, assignment, timestamp or trust flags fails closed.
- Independent outcome join still checks the exact manifest against its separate join source. Honest updates require a new manifest in both independently bound sources; after that, frozen cohort receipt total, holdout and per-arm commitments continue to apply.
- Unit regression explicitly protects positive statistical signals against stale-digest tampering, and feature fixtures rebind deliberately revised *valid* data so denominator, cohort mismatch and human-decision tests still exercise their intended guards.

## Strict limitation

This checksum detects inconsistent fields under a previously declared digest; a source controlling its own hashing is **not independently authenticated** by the checksum. A trustworthy provider/assignment adapter, current consent, human decision and other existing gates remain separately required and all effects remain unauthorized. No production send, spend, publish, auto-promotion, refund, retry or rollback is enabled. TASK-0091 remains **IN_PROGRESS** pending holistic independent evidence and guarded formal acceptance.
