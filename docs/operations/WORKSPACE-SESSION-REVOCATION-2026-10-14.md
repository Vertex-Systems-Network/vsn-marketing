# Workspace session revision audit — 2026-10-14

A browser session now receives a monotonic per-user issuance generation after first-party login. Workspace-scoped deactivation and identity-manage administrators record a revocation threshold under the same locked user row. The tenant middleware checks every protected workspace request and the directory suppresses links for that workspace until a new login. Another workspace stays accessible.

No raw cookie or password is logged. Audit metadata records the workspace, subject and numeric generation. Membership removal and revocation run in one transaction; a failed audit rolls both back. Re-adding a user does not revive an old session. An unauthenticated request remains under the existing auth middleware, and the admin endpoint retains tenant, local permission, CSRF and throttling controls.

## Migration safety

The migration adds `identity_session_issuances` and `workspace_session_revocations` with unique user/workspace keys and foreign keys. No existing records are rewritten. Framework migration markers make a completed apply idempotent; a partial DDL failure needs schema inspection and restoration of the pre-migration snapshot before retry. The tables are transactionally used on PostgreSQL; SQLite is only a local test substitute. Concurrent issuance and revocation serialize on the user row, while unique keys prevent duplicate threshold rows. The down method drops new tables and their data, so a rollback after use needs a backup and explicit operator decision. Production backup/restore is not claimed here.

Legacy sessions without a generation pass only while no revocation exists for their workspace; after revocation they are denied there. The global browser cookie remains valid for other workspaces, so this is workspace authority revocation rather than forced global logout. A request already authorized before the transaction commits may finish. General API tokens, cross-transport session termination and SSO are pending TASK-0095 work.
