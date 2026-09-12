# TASK-0024 PHASE-04 Delivery Certification Contract

Status: **in progress / fail closed**

TASK-0024 is the terminal PHASE-04 certification task. It does not create new delivery capability and it does not authorize production capacity claims. It certifies the accepted TASK-0019 through TASK-0023 delivery surface on one exact acceptance head.

## Certification rules

1. Prior task completion is necessary evidence, not automatic TASK-0024 certification.
2. Every acceptance criterion must point to executable repository evidence and remain valid on the final exact head.
3. Missing, malformed, stale, environment-mismatched, or contradictory evidence is blocking.
4. Environment-sensitive performance thresholds remain fail-closed while marked `TBD_MEASURED` in `docs/operations/TASK-0023-DELIVERY-SLOS.md`.
5. Hosted CI wall-clock duration is not production SLO evidence.
6. Numeric queue-age, end-to-end latency, sustainable-throughput, or reconciliation-lag acceptance thresholds require reproducible production-representative benchmark evidence plus explicit Delivery-owner approval. This contract does not invent those values.
7. No sender-domain/deliverability, credentials/paid-send activation, content studio, campaign/publishing, journey, or other PHASE-05+ implementation may be introduced to make certification pass.

## Acceptance matrix

| TASK-0024 AC | Required certification evidence | Current disposition |
|---|---|---|
| AC-1 — canonical message/recipient inputs and immutable execution snapshots | Completed TASK-0020 contract; `tests/Feature/DeliverySnapshotFoundationTest.php`; `tests/Integration/DeliverySnapshotPersistenceTest.php`; final-head backend/integration evidence | **Evidence present; final-head certification required** |
| AC-2 — idempotent queue routing, rate/quota controls, fairness and backpressure | Completed TASK-0021 contract; `tests/Feature/DeliveryOperationAdmissionTest.php`; `tests/Feature/DeliveryQuotaAdmissionTest.php`; `tests/Integration/DeliveryEngine/DeliveryAdmissionConcurrencyTest.php`; TASK-0023 queue/load evidence | **Evidence present; final-head certification required** |
| AC-3 — retry, ambiguity, circuit breaker, dead letter, reconciliation and safe failover | Completed TASK-0022 contract; `tests/Integration/DeliveryEngine/DeliveryRecoveryDuplicateSafetyTest.php`; `tests/Integration/Providers/DeliveryProviderFaultMatrixTest.php`; final recovery/provider certification lanes | **Evidence present; integrated final-head certification required** |
| AC-4 — production-parity PostgreSQL/Redis, concurrency, saturation, restart/recovery/provider fault evidence and measured SLO/performance acceptance | `tests/Integration/DeliveryEngine/DeliveryPostgresContentionTest.php`; `tests/Integration/DeliveryEngine/DeliveryRedisFaultInjectionTest.php`; `tests/Integration/DeliveryEngine/DeliveryQueueSaturationTest.php`; `tools/delivery_load_harness.py`; TASK-0023 SLO contract | **BLOCKED:** environment-sensitive queue-age p95/p99, E2E p95/p99, sustainable throughput and reconciliation-lag thresholds remain `TBD_MEASURED`; reproducible benchmark evidence and Delivery-owner approval are still required |
| AC-5 — workspace/brand isolation, redaction, auditability and no earlier security regression | Earlier PHASE-04 tenant-safe acceptance; `tests/Feature/DeliveryEngine/DeliveryTelemetryHotspotTest.php`; `tests/Feature/Security/DeliveryTelemetryRedactionTest.php`; final security certification lane | **Evidence present; final-head certification required** |
| AC-6 — exact-head application/integration/architecture/static/format/frontend/E2E/security/continuity gates | GitHub-hosted required checks on the final TASK-0024 acceptance head; no stale-green reuse | **Final-head only; not satisfiable in advance** |
| AC-7 — scope discipline and auditable closeout | Final diff/commit audit, canonical task/state/checkpoint/journal transition, no PHASE-05+ implementation | **Final-closeout only** |

## TASK-0019 through TASK-0023 evidence boundary

- **TASK-0019** established the provider-neutral delivery contract and research constraints. Provider facts are provenance; canonical execution may not branch on provider names.
- **TASK-0020** established immutable, workspace-safe message and recipient execution snapshots and stable business-intent identity.
- **TASK-0021** established durable logical-operation idempotency, queue admission, rate/quota enforcement, backpressure, fairness and tenant-safe concurrency semantics.
- **TASK-0022** established deterministic provider-neutral retry classification, tenant-scoped circuit breakers, dead-letter/reconciliation semantics and compatible failover that cannot replay accepted or unresolved ambiguous sends.
- **TASK-0023** added production-representative PostgreSQL/Redis load, contention, saturation, Redis latency/interruption, provider-fault, recovery/duplicate, telemetry/redaction and deterministic regression-gate evidence. It deliberately did **not** approve environment-sensitive numeric SLO thresholds.

## Numeric threshold decision rule

TASK-0024 must fail closed while any required environment-sensitive threshold is `TBD_MEASURED`.

A numeric threshold set may be accepted only when all of the following are committed and reviewable:

- exact source commit SHA;
- PHP/Laravel, PostgreSQL and Redis versions;
- runner/resource assumptions;
- deterministic scenario seed and workload parameters;
- warmup separated from the measurement window;
- repeated raw observations sufficient to reproduce reported percentiles/throughput/reconciliation lag;
- no mixing of fault scenarios with steady-state acceptance;
- explicit Delivery-owner approval identifying the accepted threshold set and evidence revision.

Until then, benchmark observations may be collected and compared, but they are evidence inputs rather than certification thresholds.

## Final closeout gate

TASK-0024 may transition to `completed` only when:

- AC-1 through AC-7 are individually evidence-backed;
- the AC-4 `TBD_MEASURED` blocker has been resolved by reproducible benchmark evidence plus an approved numeric threshold set;
- deterministic safety/regression gates pass;
- all applicable exact-head backend, integration, architecture, static, format, frontend, E2E, security and AI-continuity checks are green on the same acceptance head;
- the accepted diff contains no PHASE-05+ product implementation; and
- canonical task index, roadmap, current state, checkpoint and append-only journal are synchronized by the Supervisor closeout transaction.

Any unmet condition leaves TASK-0024 open and blocks PHASE-04 certification.
