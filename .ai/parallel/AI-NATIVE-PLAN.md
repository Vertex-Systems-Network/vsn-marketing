# AI-Native Parallel Plan — TASK-0023 Delivery SLO / Load / Fault Certification

Status: **active** — TASK-0023 is the canonical PHASE-04 task. The implementation cycle provisions ten disjoint worker lanes from trusted main `e93157c4042967a8931b914eff9175b2d75cb94e`; a Supervisor control-activation slice lands their registry/leases before workers begin writes.

Supervisor: `supervisor-main`  
Control branch: `supervisor/task-0023-parallel-integration`  
Trusted baseline: `e93157c4042967a8931b914eff9175b2d75cb94e`  
Broadcast channel: GitHub issue #43  
Completion signal: `Work Done and Submitted`

The trusted baseline passed post-merge AI Continuity Guard, Application Foundation CI, Security Supply Chain CI, Release Integrity, and OpenSSF Scorecard. Persistent Supervisor issue #102 is `HEALTHY`, active task is TASK-0023, parallel mode is active, and no blocker is reported.

All ten worker branches plus the Supervisor control branch were pre-created from the trusted baseline before these plan/registry mutations. Worker write scopes are disjoint and avoid Supervisor-owned `.ai/**`, `.github/**`, configuration, migrations, shared Core runtime, and provider connector contracts.

<!-- WORKSTREAM_TABLE_START -->
| Merge group | Workstream | Module/capability | Slot | Assigned agent | Start status | Branch | PR merge strategy | Resume/sync strategy |
|---:|---|---|---|---|---|---|---|---|
| 10 | WS-0023-SLO-CONTRACTS | Settings — measurable queue-age/throughput/saturation/reconciliation/error/p95/p99 SLI/SLO contract | `occupied` | `worker-slo-contracts` | `active` | `agent/task-0023-slo-contracts` | squash | merge latest main before resume |
| 20 | WS-0023-LOAD-HARNESS | Core — deterministic normal/burst/quota/saturation load harness | `occupied` | `worker-load-harness` | `active` | `agent/task-0023-load-harness` | squash | merge latest main before resume |
| 30 | WS-0023-POSTGRES-CONTENTION | Integrations — PostgreSQL lock/contention/concurrency/recovery certification | `occupied` | `worker-postgres-contention` | `active` | `agent/task-0023-postgres-contention` | squash | merge latest main before resume |
| 40 | WS-0023-REDIS-FAULTS | Delivery — Redis interruption/latency/capacity-loss recovery certification | `occupied` | `worker-redis-faults` | `active` | `agent/task-0023-redis-faults` | squash | merge latest main before resume |
| 50 | WS-0023-PROVIDER-FAULTS | Providers — timeout/error/rate-limit/ambiguous-outcome fault matrix | `occupied` | `worker-provider-faults` | `active` | `agent/task-0023-provider-faults` | squash | merge latest main before resume |
| 60 | WS-0023-QUEUE-SATURATION | Events — queue growth/age/throughput/retry amplification/backpressure saturation evidence | `occupied` | `worker-queue-saturation` | `active` | `agent/task-0023-queue-saturation` | squash | merge latest main before resume |
| 70 | WS-0023-RECOVERY-DUPLICATES | Audit — termination/restart/DLQ/reconciliation/retry duplicate-safety evidence | `occupied` | `worker-recovery-duplicates` | `active` | `agent/task-0023-recovery-duplicates` | squash | merge latest main before resume |
| 80 | WS-0023-OBSERVABILITY | Tenancy — workspace/provider/channel hotspot and blocker telemetry isolation | `occupied` | `worker-observability` | `active` | `agent/task-0023-observability` | squash | merge latest main before resume |
| 90 | WS-0023-SECURITY-TELEMETRY | Security — secret redaction and cross-workspace telemetry leakage prevention | `occupied` | `worker-security-telemetry` | `active` | `agent/task-0023-security-telemetry` | squash | merge latest main before resume |
| 100 | WS-0023-REGRESSION-THRESHOLDS | Connectors — deterministic fail-closed regression gate for measured SLO evidence | `occupied` | `worker-regression-thresholds` | `active` | `agent/task-0023-regression-thresholds` | squash | merge latest main before resume |
| 110 | WS-0023-CONTROL-ACTIVATION | Delivery — activate ten-worker registry/leases only; no product/runtime changes | `occupied` | `supervisor-main` | `active` | `supervisor/task-0023-parallel-integration` | squash | merge latest main before resume |
<!-- WORKSTREAM_TABLE_END -->

## Parallel execution rules

1. The control-activation PR lands first. Workers do not write until that registry/lease state is on `main` and their pre-created branches are fast-forwarded to the new main.
2. Ten workers may then progress concurrently only inside their registered write scopes.
3. The Supervisor remains sole owner of global state, workflow/config changes, shared runtime integration, acceptance metadata, and final task transition.
4. Each worker must stay current with `main` before resume/submission, target `main`, include a standalone `Workstream: <ID>` line, and add standalone `Work Done and Submitted` only when that lane is actually complete.
5. No worker may claim production capacity beyond measurements produced by committed harness/evidence.
6. TASK-0023 cannot complete until all acceptance criteria are measured/proven and exact-head required CI plus integration/fault suites pass.
7. No PHASE-05+ product capability is authorized.

## After control activation

After the control-activation PR merges, all ten worker branches are fast-forwarded to that new `main` and receive their first scoped implementation commits/draft PRs. A fresh Supervisor integration branch is then created from that same main for shared wiring, merge coordination, exact-head certification, acceptance evidence, and guarded TASK-0024 handoff.

No external ChatGPT schedule is part of repository supervision.
