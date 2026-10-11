# TASK-0091 — Bind Aggregate Provider Outcome Fields to Source Manifest

Status: **offline-only staged; exact-head/protected-main CI required**.

## Observed risk

`BoundedAutonomyOfflineRollbackReview` accepted a source `source_sha256` whenever it was syntactically 64 hex characters. It did not verify that the digest represented the independently reviewed aggregate provider outcome. A source could alter `confirmed_applied` into `confirmed_not_applied`, edit cost/callback counts, or replace the attempted/observed timestamp while preserving an opaque digest. Those exact fields determine whether a review is an offline non-effect candidate or an irreversible/uncertain hold.

## Guard

- Pure deterministic `BoundedAutonomyRollbackEvidenceDigest::sha256` commits to the full scope and evidence: tenant, run identity, immutable snapshot hash, observation and attempt timestamps, callback count, outcome, independent-verification flag, late/duplicate flags and external cost.
- The reviewer recomputes the expected checksum after validating schema/types, then compares with constant-time `hash_equals` before any positive or negative outcome classification. A syntactically valid but unbound digest fails closed.
- The existing deny-by-default source behavior, evaluation-clock freshness, conservative unknown/late/duplicate/cost/irreversible holds and all effect-authority denials remain unchanged.
- Regression covers an otherwise positive valid source and tampering of outcome, independently-verified flag, attempt timestamp, callback cardinality or provider cost using the old digest.
- Existing unit and feature fixtures use the exact same canonical digest when they deliberately model *new* legitimate provider evidence rather than a forged mutation. A later irreversible outcome still stays held across stale-positive replay.

## What this does not prove

A checksum is **not a provider signature** and does not authenticate a data source that is itself untrusted. Both individually verified provider attempts and the aggregate evidence require a separately trusted independent adapter and authorization before claiming live provider non-effect. No refund, retry, actual rollback, send, spend, publication or automatic promotion is permitted. TASK-0091 AC-3 and the whole task remain **NOT DONE** pending holistic independent evidence and guarded acceptance.
