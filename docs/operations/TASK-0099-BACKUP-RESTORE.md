# Tenant backup, restore and recovery runbook (TASK-0099)

Objectives: RPO 24 hours, RTO 4 hours (ADR-0006).

1. **Backup.** At least daily per workspace, call `TenantBackup::create($workspaceId, $key)` and store the returned ciphertext in EU storage (ADR-0006). Keys are 32 random bytes, base64, held in the deployment secret store and never next to backups. Losing the key makes the backup unrecoverable by design.
2. **Restore.** `TenantBackup::restore($blob, $key, $workspaceId)` verifies encryption, tenant binding and per-table hashes, then inserts missing rows in one transaction. It is safe to retry; it never deletes or rewrites existing rows.
3. **Reconcile external effects.** After restore, review `TenantBackup::externalEffectsSince($workspaceId, $takenAt)`. Messages, billing and publishing actions after the backup point already happened externally; do not resend — reconcile provider state first.
4. **Exercise.** Run `TenantBackup::exercise()` (covered in CI) and keep the `recovery_exercises` record. A failed RPO/RTO verdict is an incident.
5. **Rollback of a bad deploy.** Restore is additive; for corruption, restore into a fresh workspace and migrate verified data.
