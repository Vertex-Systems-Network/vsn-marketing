# TASK-0090 Offline Approval History Migration and Recovery Review

Carrier: PR #534. Scope: `2026_10_09_000002_create_ai_autonomy_offline_approval_decisions.php`.

## Purpose and safety boundary

The schema stores independently originated operator decisions, with monotonically ordered `sequence` numbers. The read-only application source queries the *latest* decision within `workspace_id + run_id`, rechecks the approver's **current** `ai.approve` membership via `WorkspaceAuthorizer`, and passes scope, immutable audience/content/destination, cost, volume and time evidence to `BoundedAutonomyOfflineApprovalReview`.

The migration creates **no permissions**, approval rows, execution path, model-owned approval writer, live campaign authorization or provider connection. Default outcome remains denial.

## Migration/data-recovery analysis

| Concern | Review and required recovery |
| --- | --- |
| Idempotency | The framework's migration ledger runs the migration once. A failed partial DDL application is not safe to retry blindly; inspect the version and object existence before rerunning. |
| Transactions | PostgreSQL transactional DDL should commit or roll back together; other engines may leave partial objects. Independent approver decisions are append-only at the application access boundary, not a claim of a database-level UPDATE trigger. |
| Apply marker | The ledger row must match schema presence (table, index, workspace/approver FKs) before considering the apply complete. |
| Retry | Only retry after verifying the failed transaction and repairing mismatched schema objects. Keep a repeatable pre-deploy backup. |
| Rollback / restore | The `down()` method blocks dropping non-empty approval history. A restore plan must preserve decision ordering and role/tenant references. |
| Destructive recovery | Never automatically delete independently approved/revoked decisions, silently re-enable an old approval, or bypass the permission verifier to repair data. |
| Concurrency | The DB-assigned sequence orders concurrent decision inserts. Latest rejection or revocation wins; no optimistic fallback to earlier approvals if the latest authority is missing. |
| Partial execution | Manual operator reconciliation is mandatory if object creation partially succeeds. Do not claim all databases support transactional DDL identically. |
| Backup snapshot | Confirm a real production snapshot and restore exercise before applying to a live persistent database; no such operator evidence is claimed in this PR. |

## Test expectations

- No decision or missing current permission is a deny, including revoked workspace role/permission.
- Latest rejected/revoked record blocks all earlier approvals.
- Cross-workspace and self-approval cannot obtain an offline approval match.
- Changed plan, audience, destination, content, token/cost/volume/time binding is not reusable.
- `execution_authorized` and `promotion_authorized` always remain false.
- Full Foundation, PostgreSQL integration, PHP 8.3, E2E, Security and Governance exact-head CI is required before any merge.

## Residual safety scope

This is a **read-only** approval evidence source; it does not implement trusted decision authoring, true database-level immutability triggers, privileged last-side-effect admission or reconciliation of unknown external outcomes. Those remain further TASK-0090 work, and no live side effect is enabled.
