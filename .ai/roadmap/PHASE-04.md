# PHASE-04 — Delivery Engine

## Purpose

Make outbound execution reliable, idempotent, observable, throttled, and provider-neutral while preserving the provider/channel boundaries certified in PHASE-03. Delivery owns canonical execution orchestration; provider/network-specific behavior remains behind connector capabilities.

## Entry conditions

PHASE-04 may activate only when:

1. PHASE-03 remains certified and its provider-neutrality/security evidence is intact.
2. TASK-0019 is formally registered, merged, and transactionally activated from canonical reconciliation state.
3. TASK-0019 completes the Research-First Gate using current authoritative sources before delivery implementation begins.
4. Any architecture change discovered by research is accepted through ADR governance before implementation relies on it.

## In scope

- current delivery-engine, queue, rate-limit, retry, failover, scheduling, and provider-constraint research;
- canonical message and recipient materialization;
- immutable execution snapshots and reproducible send inputs;
- marketing-versus-transactional delivery semantics;
- queue routing, idempotency, throttling, quotas, concurrency controls, and backpressure;
- retry classification, circuit breakers, dead letters, reconciliation, and policy-compatible failover;
- delivery observability, SLOs, queue-age/throughput/duplicate metrics, saturation and fault-injection evidence;
- PostgreSQL/Redis production-parity integration coverage;
- cross-workspace, consent, suppression, authorization, approval, region, budget, and provider-capability safety gates.

## Explicitly out of scope

- PHASE-05 sender-domain, sender-identity, suppression-list and deliverability-policy implementation beyond interfaces consumed by Delivery;
- PHASE-06 content/template/asset studio implementation;
- PHASE-07 campaign/publishing orchestration and calendar implementation;
- PHASE-09 journey engine implementation;
- PHASE-10 product AI runtime or model routing;
- PHASE-13 social/community implementation;
- provider-specific business logic in canonical Delivery domain/application code.

Later-phase contracts may be anticipated only where required to keep Delivery provider-neutral; their product implementation must not be pulled forward.

## Permanent invariants

- Delivery orchestrates canonical execution; Connectors own provider/network-specific behavior.
- An external provider ID is evidence/provenance, never canonical execution identity.
- Every externally visible attempt is idempotent and duplicate risk is explicitly modeled.
- Retry, failover and reconciliation never bypass consent, suppression, authorization, approval, region, budget, risk, quota, or capability policy.
- Marketing and transactional intent remain explicit and cannot silently change during execution.
- Immutable execution snapshots preserve the exact recipient/input/policy context used for an attempt.
- Queue routing and rate control are workspace/provider/channel safe and concurrency-safe.
- Unknown or unsupported provider capability fails closed.
- Operational SLO claims require measured production-representative evidence.
- Research evidence precedes implementation.

## Task chain

| Task | Weight | Purpose |
|---|---:|---|
| TASK-0019 | 15 | Research delivery-engine semantics, provider constraints, failure patterns and measurable SLO targets |
| TASK-0020 | 20 | Canonical message, recipient materialization and immutable execution snapshots |
| TASK-0021 | 20 | Queue routing, idempotency, throttling, quotas and backpressure |
| TASK-0022 | 20 | Retry classification, circuit breakers, dead letters, reconciliation and compatible failover |
| TASK-0023 | 15 | Delivery SLO, load, saturation, fault-injection and PostgreSQL/Redis production-parity gates |
| TASK-0024 | 10 | PHASE-04 end-to-end delivery safety, reliability and performance certification |

Weights total 100 for PHASE-04 progress calculation.

## Exit criteria

PHASE-04 may complete only when:

1. A dated research pack documents current queue/rate/retry/failover semantics, provider quotas and constraints, duplicate-risk behavior, scheduling precision and operational SLO expectations from authoritative sources.
2. Canonical messages, recipient materialization and immutable execution snapshots are workspace-safe, provider-neutral and reproducible.
3. Queue routing, idempotency, rate limits, quotas and backpressure are deterministic, concurrency-safe and fail closed across workspace/provider/channel boundaries.
4. Retry classification distinguishes retryable, permanent and indeterminate outcomes; dead-letter/reconciliation behavior is explicit and auditable.
5. Circuit breakers and provider failover use only capability-compatible alternatives and never bypass policy.
6. Duplicate attempts, ambiguous provider outcomes and late reconciliation cannot produce silent double delivery.
7. PostgreSQL/Redis production-parity tests cover queue state, locks/concurrency, retries, recovery and durable execution transitions.
8. Measured load/fault evidence records p95/p99 latency where meaningful, throughput, queue age, saturation, duplicate behavior and recovery characteristics without unsupported scale claims.
9. Cross-workspace, consent/suppression/policy, capability and provider-failure regressions fail closed.
10. Full applicable backend/integration/architecture/static/format/frontend/E2E/security/AI-continuity gates are green on the exact PHASE-04 acceptance head.
11. No PHASE-05+ product capability is silently pulled forward.

## Research requirement

TASK-0019 must create `.ai/research/PHASE-04/TASK-0019-RESEARCH.md` using dated current authoritative/primary sources. Fast-changing provider limits, retry guidance, scheduling semantics and API constraints must be revalidated before implementation tasks depend on them.

## Exact continuation principle

PHASE-04 starts with research and measurable execution semantics. It does not start by adding routing/failover product code or assuming provider behavior from stale documentation.
