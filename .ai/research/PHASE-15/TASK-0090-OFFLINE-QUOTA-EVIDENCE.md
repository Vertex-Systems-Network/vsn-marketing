# TASK-0090 Offline Atomic Quota Implementation Evidence

Status: implementation and adversarial fixtures staged in PR #533; not complete or production-authorized.

## Existing certified controls

- PR #531: fail-closed operator preflight with independent policy snapshot source, volume/token/action/cost/attempt ceilings and emergency-stop behavior.
- PR #532: exact immutable approval evidence review; independent source required, self-approval, expiry, revocation and changed bindings held offline.

## PR #533 scope

- Missing `ai_autonomy_global_stops` authority row or missing workspace quotas deny every claim; stop flag defaults enabled for configured rows.
- Database transaction locks global stop before workspace quota, validates current scope, policy version, quotas, snapshot and last stop state; usage increments with one durable record.
- A workspace/run replay cannot reserve twice, even if the first claim exhausted the budget; actor, snapshot, brand and policy conflicts deny.
- PostgreSQL two-worker fork contention fixture demonstrates that no more than the ceiling can be claimed.
- All returns remain `execution_authorized=false`, `promotion_authorized=false`. A recorded offline claim is not a live budget, send approval or provider authority.

## Remaining TASK-0090 acceptance

- Certified independent database approver authority and durable decision revocation/version controls.
- Terminal last-side-effect gate/stop reconciliation of uncertain irreversible provider outcomes; production side effects remain disabled.
- Red-team and complete exact-head + resulting-main gates for actual current carrier.
