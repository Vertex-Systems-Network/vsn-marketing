# TASK-0090: Offline independent approval authority — staged evidence

Status: candidate implementation, not CI-certified, not on protected main.

The offline proposal approval review was certified under PR #532. This follow-up
adds a read-only decision store lookup and independent workspace role revalidation;
it does **not** add an approval writer, approval UI, provider adapter or sending authority.

## Authority model

- A decision has an immutable database-generated `decision_sequence`. The most
  recently appended record always wins, including revoked/rejected decisions with
  the same clock time as earlier approvals.
- No decision rows are seeded by migration. The default approval source remains
  deny-all. The database source explicitly reads only the caller's canonical
  organization/workspace, the requested run, and the latest decision.
- A missing, deleted or role-revoked approver fails closed through
  `WorkspaceAuthorizer::allows(..., PermissionCatalog::AI_APPROVE)`.
- The domain review continues to bind the exact snapshot, audience, content,
  destination, cost, volume and time window and rejects self-approval.
- Both positive operator-review and negative results return
  `execution_authorized=false` and `promotion_authorized=false`.

## Schema and recovery review

Laravel migrator applies the schema only in a separately controlled environment.
There is no production schema apply, backup or restore certification in this batch.
The down migration refuses destructive drops while approval evidence exists;
export and independently verify a restore plan before any future production apply.
Concurrent decision ordering uses the database-generated append sequence, never
untrusted timestamps or lexicographic UUID ordering.

## Required certification before merge

- Feature tests on SQLite for independent actor role, revocation, foreign
  organization, changed exact binding and same-clock decision ordering.
- PHP formatting and static analysis.
- PostgreSQL migration/integration compatibility, E2E, Security and Governance.
- Resulting-main controls after exact-head verified merge.

This module alone does not close TASK-0090 AC-2 or grant external effects.
