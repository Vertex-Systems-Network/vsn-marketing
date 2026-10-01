# PHASE-10 AI gateway implementation boundary

TASK-0055 first provides a server-owned route filter and response envelope. Authenticated `TenantContext` supplies workspace scope; caller and model payloads cannot choose another workspace. Routes require explicit workspace, region, data class, risk, capability, credential reference and reservation bound. There are no registered live routes or provider credentials in this carrier. `DenyingAiBudgetLedger` is the default binding. Model output is withheld until TASK-0057 schema and semantic/tool policy validation exists.

The additive budget tables support an explicitly configured per-workspace UTC-day limit and unique trace reservation. A budget row is never created implicitly. `DatabaseAiBudgetLedger` locks the budget row and serializes reservations; it reconciles actual cost only once and retains a reservation when provider usage is unknown. The new ledger is not bound to runtime traffic until concurrency, telemetry, circuit break and provider contract tests are complete. No production numeric capacity is asserted.

## Migration/data-safety review

- **Idempotency:** Laravel migration registry controls one application; table and primary/foreign keys reject duplicate schema and trace writes. Rerun requires standard migration registry recovery, not manual blind SQL.
- **Transaction boundaries:** PostgreSQL migration DDL is transactional; each reservation/settlement uses a DB transaction and budget row lock. A partial provider call never shares its transaction with a model request.
- **Apply/marker failure:** Inspect schema and Laravel migration table before rerunning; never drop populated budget evidence to clear a marker.
- **Retries:** Unique `(workspace_id, trace_id)` denies replay reservation; budget/settlement transactions retry DB deadlocks at most three times. Unknown provider cost remains reserved for explicit reconciliation.
- **Rollback/restore:** `down()` refuses non-empty tables. A populated production rollback needs a separate approved backup, export and restore procedure.
- **Destructive recovery:** No truncate, backfill or production data deletion is performed by this carrier.
- **Concurrency:** Parent budget row lock serializes competing workspace reservations; unique trace guards duplicate workers. PostgreSQL integration must test this under actual contention before enabling a route.
- **Partial execution:** No budget row means denial. Reservation is recorded before provider invocation; failed/unknown usage is retained, avoiding accidental budget release.
- **Backup/snapshot:** New empty tables are additive. Before any future production migration or rollback, take and verify a database snapshot under the deployment policy. No production migration is authorized or executed here.
