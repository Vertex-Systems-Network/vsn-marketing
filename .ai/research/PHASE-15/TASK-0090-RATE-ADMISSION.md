# TASK-0090: Atomic offline rate claims

Status: staged, not acceptance-certified.

A separately owner-configured workspace/UTC-day rate policy must exist and agree with the immutable quota policy version. It is not auto-created, and absent policy denies every new claim. Within the same database transaction and global-stop/workspace-quota locks, the rate row is locked before durable run reservation; one successful run consumes an attempt slot in its fixed UTC 60-second window. Duplicate run replay does not consume another attempt. Overlapping workers cannot bypass the locked quota and rate rows. A backward clock, policy revision or foreign organization workspace fails closed.

Scope remains **offline resource reservation only**: no provider dispatch, marketing send, ad spend, billing or production promotion is enabled. Final review is not a last-side-effect authorization. Evidence required: negative feature and PostgreSQL contention tests plus exact-head and protected-main CI. TASK-0090 criteria remain unmarked until independently certified.
