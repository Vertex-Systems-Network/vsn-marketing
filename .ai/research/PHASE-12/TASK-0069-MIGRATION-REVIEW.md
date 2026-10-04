# TASK-0069 additive migration review — 2026-10-03

Migration: `2026_10_03_000001_create_analytics_fact_foundation.php`. Adds four derived tables only; existing customer events/contacts and production data are not rewritten. Review is repository engineering approval, not permission to execute production migration or destructive rollback.

- Idempotency: Laravel migration ledger owns successful apply identity. `up()` deliberately fails on unexpected preexisting tables; it never silently accepts an unknown partial schema. Application event UUID and scope/source hash unique constraints enforce fact dedupe; conflicts and tombstones have stable hash identities. Report snapshots have explicit immutable UUIDs.
- Transactions: PostgreSQL migration DDL is transactional through the Laravel migrator. Application admission/invalidation locks the canonical workspace row inside a retry-bounded transaction. SQLite feature tests do not prove PostgreSQL locking.
- Apply marker/recovery: commit schema plus ledger atomically on PostgreSQL. An ambiguous interrupted run requires inspecting both ledger and schema on the target; never insert a migration marker to conceal partial execution.
- Retry: rerun `migrate` only after ledger/schema reconciliation. Application transactions have at most three retry attempts; uniqueness remains the final database guard. Do not blindly rerun a partially applied nontransactional DDL path.
- Rollback/restore: `down()` drops derived facts, conflict evidence, invalidation tombstones and snapshots. Prefer forward repair. Restore the pre-change snapshot (including the migration ledger) if rollback is required; dropping tombstones without restoring them could re-admit previously invalidated subjects.
- Destructive recovery: no production down/refresh/fresh or automatic tombstone purge is authorized. Exact backup/restore and independent verification are prerequisites for a target-environment destructive operation.
- Concurrency: schema deployment uses a single migration runner. Runtime competing admission/replay test is included in `AnalyticsFactsPostgresTest`; CI execution remains pending until its actual PASS log is inspected.
- Partial execution: transaction rollback should leave no tables/marker on PostgreSQL. Unexpected tables without marker are a blocker requiring inspection/forward repair, not a reason to skip creation.
- Backup/snapshot: production execution requires an operator-owned restorable database snapshot and restore validation before apply. No production backup or restore was performed in this task. CI uses disposable synthetic databases.

Validation evidence is carried in TEST-STATE and the checkpoint. Runtime privacy invalidation deletes derived records only; canonical Events remain intact, and future admission is denied by the durable tombstone. No production invalidation was executed.
