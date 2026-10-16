# TASK-0099 acceptance matrix

Objectives: RPO 24h / RTO 4h (ADR-0006). Exercised in CI SQLite and PostgreSQL integration environments; no production backup target is claimed.

| Criterion | Implementation | Evidence |
| --- | --- | --- |
| AC-1 encrypted tenant-separated backup/restore with integrity and audit linkage | `TenantBackup`: AES-256-GCM with workspace id as authenticated data, per-table SHA-256 manifest, audit head captured; restore verifies hashes and tenant, inserts missing rows only, records `recovery.backup.restored` with source audit head | `TenantBackupTest` round-trip, ciphertext opacity, idempotent replay, audit linkage |
| AC-2 measured exercise records actual loss and elapsed restore time | `TenantBackup::exercise` stores `recovery_exercises` row and audit event with rows lost, loss window, elapsed seconds and RPO/RTO verdicts; CI log line `RECOVERY_EXERCISE` | measured exercise test |
| AC-3 partial restore, lost key, queue replay, external-effect reconciliation | all-or-nothing transaction; wrong/lost key and cross-tenant target fail; tampered ciphertext fails; re-running restore is a no-op; post-backup delivery/billing/publishing effects are listed for reconciliation, never replayed | corresponding `TenantBackupTest` cases |

Operational runbook: `docs/operations/TASK-0099-BACKUP-RESTORE.md`.
