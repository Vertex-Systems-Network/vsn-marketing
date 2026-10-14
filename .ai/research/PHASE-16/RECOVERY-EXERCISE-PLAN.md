# Phase 16 recovery exercise plan — 2026-10-13

Status: **PLANNED / NOT EXECUTED**. `OfflineRecoveryExercise` checks an encrypted synthetic fixture only. It is not a backup, restore, recovery-time objective or production integrity certificate.

## Preconditions

1. An approved business impact analysis supplies a recovery point objective (RPO) and recovery time objective (RTO) per service and region. Both remain unset.
2. Inventory PostgreSQL base backups/WAL, Redis queues and failed jobs, object storage, encryption keys, audit and outbox records, analytics exports, provider-side effects and deployment artifacts. Identify every workspace and regional copy.
3. Obtain an isolated environment, access to the matching encryption keys and a verified immutable snapshot. Confirm backup provenance and integrity before any restore. Never overwrite production during an exercise.

## Measured exercise

1. Record incident time, last acknowledged canonical transaction, snapshot/WAL points, last queued job and external provider acknowledgement references. Start a monotonic restore timer.
2. Restore the database and object versions in isolation. Verify schema migration level, row counts and workspace/brand referential boundaries; check audit chain and outbox correspondence without replaying effects.
3. Restore queue state with consumers disabled. Deduplicate work by stable operation IDs, reconcile provider acknowledgements and suppression/consent before selectively releasing workers. Test one partial snapshot, missing key, corrupt object, duplicate job and cross-workspace reference.
4. Measure actual lost acknowledged records and latest recoverable commit against incident time; compare to the approved RPO. Measure elapsed time until a representative workspace can serve authenticated read and a safe delivery decision; compare to the approved RTO. Record discrepancies, not just pass/fail.
5. Rehearse application rollback against the restored schema and compatible code version. Keep production sending and financial authority disabled until separately approved.

## Evidence required to close TASK-0099

- Immutable snapshot and key-provenance references, region and workspace scope.
- Exact restore commands and versions, redacted logs, measured timestamps and counts, data-loss calculation, queue/provider reconciliation and audit integrity result.
- Independent reviewer of failures and objectives; remediation and retest for any failed boundary.

No selected backup provider, key store, region, approved RPO/RTO or actual restore environment is recorded in this repository. The local synthetic test cannot satisfy these evidence requirements.
