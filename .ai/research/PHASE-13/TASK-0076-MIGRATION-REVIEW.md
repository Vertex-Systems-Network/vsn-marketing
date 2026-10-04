# TASK-0076 migration review

Migration: 2026_10_04_000015_create_messaging_operations_table.php. Additive, not applied to a production environment.

- Idempotency / apply marker: known existing table requires all reviewed columns, primary identity, workspace/idempotency unique constraint and workspace FK. Missing marker can rerun safely. Unknown partial tables fail closed.
- Transactions: Laravel migration transactions on PostgreSQL protect schema application; reservation/reconciliation use transactions with three bounded deadlock retry attempts. No provider effect inside a transaction.
- Retry / concurrency: unique key arbitrates first reservations, followed by row lock and identity comparison. PostgreSQL two-process contention test is committed; local skip is disclosed until CI executes it.
- Rollback / destructive recovery: down refuses non-empty operation evidence; empty table rollback/reapply is tested. No production destructive operation is authorized. Workspace deletion remains the existing explicit FK cascade boundary.
- Partial execution: unknown partial schema is preserved and refused; reviewed table re-entry preserves its row. Restore reviewed schema from an operator-reviewed snapshot, then retry; never silently adopt an unknown table.
- Backup / snapshot: before any real rollback, take a tenant-scoped reviewed database backup containing operation rows, verify restore into isolated infrastructure and retain original evidence. No backup capture or restore is claimed by repository tests.
