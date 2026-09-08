# AI-Native Parallel Plan — TASK-0021 Delivery Admission Completion

Status: **active** — TASK-0021 is decomposed into four conflict-safe writable worker lanes. A Supervisor coordination branch, `supervisor/task-0021-parallel-integration`, was pre-created from the same trusted baseline but is intentionally not a registered writable workstream in this activation change; shared integration starts only after accepted worker deliveries.

Supervisor: `supervisor-main`  
Broadcast channel: GitHub issue #43  
Completion signal: `Work Done and Submitted`

All five cycle branches (four workers plus the reserved Supervisor coordination branch) were pre-created from trusted `main` `4e2470b8f019faecde3bd0c64e089f1b5de5fdac` before this cycle's planning/code mutation.

The cycle remains inside TASK-0021. Retry classification, circuit breakers, dead letters, reconciliation, provider failover, sender-domain/deliverability policy, credentials/paid sends, and TASK-0022+ behavior remain excluded.

<!-- WORKSTREAM_TABLE_START -->
| Merge group | Workstream | Module/capability | Slot | Assigned agent | Start status | Branch | PR merge strategy | Resume/sync strategy |
|---:|---|---|---|---|---|---|---|---|
| 10 | WS-0021-FAIRNESS-POLICY | Deterministic provider-neutral workspace fairness policy primitives | `occupied` | `agent-fairness-01` | `leased_ready_to_start` | `agent/task-0021-fairness-policy` | squash | merge latest main before resume |
| 10 | WS-0021-REDIS-ADMISSION | Redis-backed atomic admission coordination without provider-specific branching | `occupied` | `agent-redis-admission-01` | `leased_ready_to_start` | `agent/task-0021-redis-admission` | squash | merge latest main before resume |
| 20 | WS-0021-BACKPRESSURE-OBSERVABILITY | Machine-readable backpressure reason, age and visibility primitives | `occupied` | `agent-backpressure-observability-01` | `leased_ready_to_start` | `agent/task-0021-backpressure-observability` | squash | merge latest main before resume |
| 20 | WS-0021-CONCURRENCY-CERT | Production-representative PostgreSQL and Redis concurrency certification coverage | `occupied` | `agent-concurrency-cert-01` | `leased_ready_to_start` | `agent/task-0021-concurrency-cert` | squash | merge latest main before resume |
<!-- WORKSTREAM_TABLE_END -->

## Conflict boundaries

- Fairness policy owns only `DeliveryFairnessPolicy`, `DeliveryFairnessDecision`, and its unit test.
- Redis admission owns only the admission-coordinator contract/Redis implementation and its integration test.
- Concurrency certification owns only the two TASK-0021 concurrency integration-test files.
- Backpressure observability owns only the immutable backpressure snapshot/query primitives and its focused feature test.
- Supervisor retains shared ownership of canonical `.ai/**`, README, migrations, service-provider wiring, and the existing database-admission repository; workers do not mutate those paths.

## Merge / synchronization order

1. Merge group 10 workers can develop in parallel.
2. Merge group 20 workers can develop in parallel, but every still-open lane must synchronize latest `main` after each approved merge.
3. After worker contracts are accepted, the Supervisor uses the reserved coordination branch (synchronized to latest `main`) for bounded shared integration and canonical closeout work.
4. TASK-0021 may close only after exact-head continuity, backend, PostgreSQL/Redis integration, static/format, E2E, and security gates prove all acceptance criteria.

## Required remaining evidence

- atomic Redis-backed concurrency control over the existing durable PostgreSQL operation/quota ledger;
- deterministic workspace fairness under saturation with no tenant starvation;
- machine-readable backpressure reason and age visibility;
- production-representative concurrent PostgreSQL + Redis tests proving no duplicate logical operations and no quota oversubscription;
- no widening into TASK-0022 retry/failover semantics.
