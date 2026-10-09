# TASK-0090 Offline Last-Boundary Evidence

State: staged, not certified and never permission to execute external work.

The final offline boundary locks the global emergency stop, workspace resource quota and existing durable reservation in the same order as admission. It checks live stop flags, expiry, immutable run/actor/snapshot/policy binding, reservation status and remaining budget ceilings without double-reserving counters.

The approval is independently resolved from the latest database decision with *current* approver-role authorization, and the exact audience/content/destination/time/cost/volume binding must still match. A rejected/revoked approval, lost permission, stopped workspace, expired policy, changed snapshot or held reservation cannot pass even when a previous preview looked valid.

A successful result is deliberately named `offline_final_review_ready` and always includes:
- `execution_authorized=false`
- `promotion_authorized=false`
- `external_outcome_verified=false`

An actual side-effect boundary would additionally need channel-specific consent, suppression, RBAC, provider credential/scope status, durable attempt/idempotency, rate limits, last-moment kill-switch and independently sourced provider outcomes. None of those production effects is enabled or claimed by this staged test-only service.

Adversarial tests cover stop toggles after initial reservation, latest approval revocation, role removal, changed cost cap, and non-releasable held reservation. Before merging this carrier, exact-head Foundation, PostgreSQL integration, PHP 8.3, browser E2E, Security and Governance checks must pass.

TASK-0090 AC-1/AC-2/AC-3 remain marked incomplete until independent certification and sufficient real-effect safety evidence exist.
