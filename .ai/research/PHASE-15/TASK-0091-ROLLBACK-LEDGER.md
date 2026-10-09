# TASK-0091 Append-Only Offline Rollback Review Events

Status: PR #544 staged, not yet exact-head certified. This is not an external provider proof or successful rollback.

- Existing `BoundedAutonomyOfflineRollbackReview` holds unknown, late, duplicate, verified-applied and nonzero-cost outcomes.
- `DatabaseBoundedAutonomyOfflineRollbackReviewEvent` records strictly operator-only review events per tenant/workspace/run. It requires current campaign-read permission and a locked canonical workspace matching its organization.
- Event fingerprint and per-run sequence protect against duplicate callbacks, conflicting actor/snapshot replay and concurrent overwrite. A previously held outcome cannot be erased by a later apparent non-effect: the history retains both records and continues requiring manual reconciliation.
- Migration never auto-grants authority or deletes existing evidence during rollback. Feature fixtures cover unknown, repeated, confirmed-applied, apparent non-effect, forged claims, revoked permission and cross-organization access.
- A positive offline review does **not** mean rollback performed, real-world provider effect verified, refund approved, retry allowed, campaign execution permitted or winner promoted.
- External outcome adapter authenticity, human-scoped promotion/rollback decisions and additional failure/race certification remain TASK-0091 scope. Do not mark AC-1..3 complete on these fixtures alone.
