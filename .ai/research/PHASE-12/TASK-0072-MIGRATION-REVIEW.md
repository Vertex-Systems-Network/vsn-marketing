# TASK-0072 migration safety review — 2026-10-03

Additive `2026_10_03_000002_create_analytics_report_schedules.php` creates schedules and durable run evidence. No production migration, backup, restore, data deletion or provider operation was executed or authorized by this engineering review.

- Idempotency: Laravel apply ledger; verified existing columns, unique identity and scope foreign keys required before accepting reentry. Unknown partial schema fails closed. Unique schedule/window prevents duplicate claims.
- Transactions: Laravel PostgreSQL transactional DDL commits tables and migration ledger together. Runtime lock order is schedule then run; snapshot, completion and next-window advancement commit in one transaction. Failed generation rolls back snapshot before bounded failure evidence.
- Apply marker/recovery: inspect target ledger/schema after interruption; never manufacture a successful marker. Known complete schedule table plus absent run table can be completed; unknown partial table is rejected.
- Retry: schema replay and empty rollback/reapply tested. Runtime at most three attempts, two-minute lease, token fencing, bounded due batch and recovery horizon; expired claim and authority revocation tests preserve attempt evidence.
- Rollback/restore: empty derived tables may be removed in reverse FK order; any schedule/run evidence makes `down()` refuse. Prefer forward repair. Any target restore requires a verified pre-change database snapshot including ledger and derived evidence.
- Destructive recovery: production `down`, `fresh`, `refresh` or evidence purge remains unauthorized. Test-only synthetic tables are isolated disposable fixtures.
- Concurrency: one migration runner; PostgreSQL competing worker test requires one committed snapshot/run, losing worker returns false. Actual CI PASS log pending, no local PG claim.
- Partial execution: PostgreSQL transactional failure should roll back tables/marker. SQLite test explicitly recovers a known complete first table with missing second table and rejects an unknown incomplete table.
- Backup/snapshot: restorable operator-owned target snapshot and restore validation are prerequisites before production apply. No production snapshot/restore claim. CI PostgreSQL test creates/drops only its named synthetic namespace.
