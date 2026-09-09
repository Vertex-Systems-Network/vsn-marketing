# AI-Native Parallel Plan — TASK-0022 Recovery Safety

Status: **active** — TASK-0022 is decomposed into five conflict-safe writable domain-policy lanes. A Supervisor coordination branch, `supervisor/task-0022-parallel-integration`, was pre-created from the same trusted baseline and retains all shared persistence, state-machine, migration, wiring, provider-adapter integration, canonical `.ai/**`, and final acceptance work.

Supervisor: `supervisor-main`  
Broadcast channel: GitHub issue #43  
Completion signal: `Work Done and Submitted`

All six cycle branches (five workers plus the reserved Supervisor coordination branch) were pre-created from trusted `main` `775f8a47cfa4fe092bc90346788f37567f7e3e36` before this cycle's planning or code mutation. That trusted head passed post-merge AI Continuity run `34394642732`, Application Foundation run `34394642755`, Security Supply Chain run `34394642810`, Release Integrity run `34394642691`, and OpenSSF Scorecard run `34394642730`.

The cycle remains strictly inside TASK-0022. Sender-domain/deliverability policy, credential or paid-send activation, PHASE-04 SLO/load certification, and TASK-0023+ behavior remain excluded. Provider ambiguity is never treated as ordinary retry permission: accepted operations never reroute, and unresolved ambiguous attempts must reconcile before replay or failover.

<!-- WORKSTREAM_TABLE_START -->
| Merge group | Workstream | Module/capability | Slot | Assigned agent | Start status | Branch | PR merge strategy | Resume/sync strategy |
|---:|---|---|---|---|---|---|---|---|
| 10 | WS-0022-RETRY-CLASSIFICATION | Provider-neutral retry and recovery classification primitives | `occupied` | `agent-retry-classification-01` | `leased_ready_to_start` | `agent/task-0022-retry-classification` | squash | merge latest main before resume |
| 10 | WS-0022-CIRCUIT-BREAKER | Tenant-scoped explicit circuit-breaker state and transition policy | `occupied` | `agent-circuit-breaker-01` | `leased_ready_to_start` | `agent/task-0022-circuit-breaker` | squash | merge latest main before resume |
| 20 | WS-0022-RECONCILIATION | Idempotent ambiguity reconciliation decision primitives | `occupied` | `agent-reconciliation-01` | `leased_ready_to_start` | `agent/task-0022-reconciliation` | squash | merge latest main before resume |
| 20 | WS-0022-DEAD-LETTER | Auditable terminal dead-letter eligibility policy | `occupied` | `agent-dead-letter-01` | `leased_ready_to_start` | `agent/task-0022-dead-letter` | squash | merge latest main before resume |
| 20 | WS-0022-FAILOVER-POLICY | Compatible failover eligibility preserving logical-operation identity | `occupied` | `agent-failover-policy-01` | `leased_ready_to_start` | `agent/task-0022-failover-policy` | squash | merge latest main before resume |
<!-- WORKSTREAM_TABLE_END -->

## Conflict boundaries

- Retry classification owns only `DeliveryAttemptOutcomeClass`, `DeliveryRecoveryAction`, `DeliveryFailureObservation`, `DeliveryRetryDecision`, `DeliveryRetryPolicy`, and its unit test.
- Circuit breaker owns only `DeliveryCircuitBreakerState`, `DeliveryCircuitBreakerKey`, `DeliveryCircuitBreakerDecision`, `DeliveryCircuitBreakerPolicy`, and its unit test.
- Reconciliation owns only `DeliveryReconciliationResolution`, `DeliveryReconciliationEvidence`, `DeliveryReconciliationDecision`, `DeliveryReconciliationPolicy`, and its unit test.
- Dead letter owns only `DeliveryDeadLetterReason`, `DeliveryDeadLetterDecision`, `DeliveryDeadLetterPolicy`, and its unit test.
- Failover owns only `DeliveryRouteAcceptanceState`, `DeliveryFailoverDecision`, `DeliveryFailoverPolicy`, and its unit test.
- Supervisor retains shared ownership of canonical `.ai/**`, README, config, migrations, `DeliveryOperationState`, existing repositories, service-provider wiring, provider-adapter integration, shared application workflow code, and final PostgreSQL/Redis concurrency certification. Workers do not mutate those paths.

## Merge / synchronization order

1. Merge-group 10 retry-classification and circuit-breaker lanes may develop concurrently.
2. Merge-group 20 reconciliation, dead-letter, and failover-policy lanes may also develop concurrently because their declared files are disjoint; their PRs remain subject to current-main ancestry and exact-head CI.
3. After every approved worker merge, all still-open workers and the reserved Supervisor branch must synchronize latest `main` before another write, following the required issue #43 broadcast protocol.
4. Only after accepted worker primitives are integrated may the Supervisor extend shared delivery-operation states/persistence, add attempt/reconciliation/dead-letter/breaker persistence and provider-error adaptation, wire application recovery flows, and add production-representative race certification.
5. TASK-0022 may close only after exact-head continuity, backend, PostgreSQL/Redis integration, static/format, frontend/E2E, and security gates prove all acceptance criteria.

## Required remaining evidence

- deterministic normalization from provider-neutral failure observations into permanent, auth/policy, rate-limited, transient-pre-accept, transient-server, ambiguous-transport, and accepted outcomes;
- explicit tenant-scoped closed/open/half-open breaker behavior without cross-workspace health leakage;
- dead-letter policy that never converts unresolved ambiguity into a terminal replayable failure;
- idempotent reconciliation that keeps ambiguity durable until evidence proves accepted, proves retry-safe non-acceptance, or requires operator resolution;
- failover policy that never reroutes accepted or unresolved ambiguous operations and rechecks capability, policy, readiness, quota, breaker, and tenant safety;
- durable operation/attempt/reconciliation persistence plus concurrent PostgreSQL/Redis tests proving accepted sends are not replayed and ambiguous sends are not blindly failed over;
- no widening into sender-domain/deliverability, credentials/paid sends, TASK-0023 load/SLO certification, or later roadmap work.
