# TASK-0090: Atomic offline rate claims

Status: staged, not acceptance-certified.

A separately owner-configured workspace/UTC-day rate policy must exist and agree with the immutable quota policy version. It is not auto-created, and absent policy denies every new claim. Within the same database transaction and global-stop/workspace-quota locks, the rate row is locked before durable run reservation; one successful run consumes an attempt slot in its fixed UTC 60-second window. Duplicate run replay does not consume another attempt. Overlapping workers cannot bypass the locked quota and rate rows. A backward clock, policy revision or foreign organization workspace fails closed.

Scope remains **offline resource reservation only**: no provider dispatch, marketing send, ad spend, billing or production promotion is enabled. Final review is not a last-side-effect authorization. Evidence required: negative feature and PostgreSQL contention tests plus exact-head and protected-main CI. TASK-0090 criteria remain unmarked until independently certified.


## Migration/recovery review

Migration `2026_10_09_000003` only creates the separate `ai_autonomy_workspace_rate_windows` table with a composite foreign key to the pre-existing quota. The migration does **not** seed rows or lower any stop, role, consent or spend control. Without an independently configured row, admission denies.

Deployment order: preserve database backup and migration markers; apply in the standard serialized migration operation; verify the new table, foreign key, UTC-day unique key and that existing workspace quotas are unchanged. Laravel's migration marker is recorded by the migration runner. If a partial DDL apply loses its marker, compare actual schema to recorded marker and recover explicitly; repeated automatic destructive repair is prohibited. The `down()` method refuses nonempty retained rate history. Reconcile provider-unknown outcomes separately and never refund an irreversible claim without independent evidence.

Per-minute fixed windows are **not** a rolling 60-second guarantee: an operator might see two bursts around the minute boundary. Until a stronger rolling-window model is independently certified, the owner-configured ceiling and daily quota remain jointly enforced; neither implies permission to send or spend.
