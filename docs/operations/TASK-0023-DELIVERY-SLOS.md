# TASK-0023 Delivery SLO Contract

Status: **environment-sensitive SLO thresholds approved from TASK-0024 Railway v7 evidence; PHASE-04 final certification pending**  
Workstream: `WS-0023-SLO-CONTRACTS`  
Acceptance evidence head: `bb47eafd3072cf6ab22eb8863014986c432d9d50`

This document defines the measurement and decision contract for TASK-0023. It intentionally does **not** turn hosted-CI wall-clock timings into production capacity claims. Deterministic safety invariants are enforced now; environment-sensitive latency and throughput thresholds below are the exact values explicitly approved by the human Delivery owner from the committed TASK-0024 Railway v7 benchmark evidence.

## Required SLIs

| SLI | Definition | Unit | Required views |
|---|---|---:|---|
| Queue age | Time from durable enqueue eligibility to admitted processing start | ms | p50, p95, p99, max |
| End-to-end delivery latency | Time from logical enqueue to terminal provider outcome or reconciliation hold | ms | p50, p95, p99 |
| Throughput | Completed logical delivery operations per measurement window | ops/s | steady, burst, saturated |
| Success/error rate | Terminal outcomes by normalized delivery classification | ratio | workspace, provider, channel |
| Retry amplification | Physical attempts divided by logical operations | ratio | steady, faulted, recovery |
| Duplicate behavior | Extra provider attempts for one logical idempotency key | count | normal, races, restart |
| Reconciliation lag | Time an ambiguous operation remains reconciling before evidence-backed resolution | ms | p95, p99, max |
| Saturation | Concurrent lease/queue/resource pressure at the point admission backpressure activates | ratio/count | Redis, PostgreSQL, provider route |

## Measurement rules

1. Every benchmark result records commit SHA, PHP/Laravel version, PostgreSQL version, Redis version, runner resources, scenario seed, operation count, concurrency, and measurement window.
2. Warm-up samples are separated from measured samples.
3. Percentiles are computed from raw per-operation observations, not averages of averages.
4. Missing or malformed measurements fail closed; they never silently pass a gate.
5. Workspace/provider/channel breakdowns must not expose credentials, message bodies, recipient PII, provider-connection secrets, idempotency material, queue-partition material, or cross-workspace data.
6. Provider timeout, rate-limit, transient/permanent failure, Redis interruption/lease loss, PostgreSQL contention, worker termination/restart, and recovery scenarios are evaluated separately from the steady-state baseline.
7. Environment-sensitive latency or throughput thresholds are enforceable only when pinned to reviewed production-representative benchmark evidence and explicit Delivery-owner approval.
8. Hosted CI test durations are evidence that a scenario executed, not production latency/throughput thresholds and not a scale claim.

## Production-parity evidence baseline

The TASK-0023 integration gate ran with `RUN_INFRA_INTEGRATION=true` against PostgreSQL and Redis service containers rather than SQLite/in-memory substitutes. The observed CI environment included PHP 8.5.10, Laravel 13.26.1, PostgreSQL 18.6 (`postgres:18-alpine`), Redis 8.10.1 (`redis:8-alpine`), and the Ubuntu 24.04 GitHub-hosted runner image. The integrated suite containing the TASK-0023 PostgreSQL contention, Redis fault, queue saturation, recovery/duplicate, and provider-fault cases passed.

The acceptance head `bb47eafd3072cf6ab22eb8863014986c432d9d50` then passed the complete Application Foundation CI and Security Supply Chain CI, including backend, PostgreSQL/Redis integration, architecture, static analysis, formatting, frontend typecheck/unit/build, Playwright smoke, SAST, dependency audit, secret scan, container scan, SBOM reproducibility, CodeQL, action-integrity, and AI continuity requirements.

## Evidence matrix

| Evidence area | Executable evidence | Decision enforced |
|---|---|---|
| Repeatable workloads | `tools/delivery_load_harness.py`, `tools/test_delivery_load_harness.py` | deterministic steady, burst, quota-constrained, and saturated operation streams with reproducible seed/order |
| PostgreSQL contention | `tests/Integration/DeliveryEngine/DeliveryPostgresContentionTest.php` | contenders serialize; stale transaction rollback recovers; enqueue replay remains duplicate-safe |
| Redis interruption/recovery | `tests/Integration/DeliveryEngine/DeliveryRedisFaultInjectionTest.php` | reconnect does not consume duplicate capacity; release/reconnect recovers; expired worker lease is recoverable; capacity stays bounded |
| Provider faults | `tests/Integration/Providers/DeliveryProviderFaultMatrixTest.php` | timeout/error/rate-limit/ambiguous outcomes map to fail-closed recovery; exhausted attempt budgets do not amplify retries |
| Queue saturation | `tests/Integration/DeliveryEngine/DeliveryQueueSaturationTest.php` | configured concurrency admits only available capacity, excess work backpressures, backlog drains after release, quota-held work does not hot-loop |
| Recovery/duplicates | `tests/Integration/DeliveryEngine/DeliveryRecoveryDuplicateSafetyTest.php` | accepted evidence replay does not create a second physical attempt; conflicting evidence fails closed; ambiguous outcomes remain in durable reconciliation |
| Hotspot telemetry | `tests/Feature/DeliveryEngine/DeliveryTelemetryHotspotTest.php` | workspace/provider/channel/blocking cause remain attributable with bounded fields and cross-workspace denial |
| Telemetry redaction | `tests/Feature/Security/DeliveryTelemetryRedactionTest.php` | sensitive message/recipient/provider-connection/idempotency/queue-partition material is excluded while safe dimensions remain observable |
| Regression gate | `tools/delivery_slo_gate.py`, `tools/test_delivery_slo_gate.py` | numeric max/min rules are deterministic; missing, malformed, non-finite, or unsupported evidence fails closed |

## Enforced deterministic gates

These gates are independent of hosted-run speed and are already suitable for regression enforcement:

| Gate | Required result | Owner / decision rule |
|---|---|---|
| Silent duplicate delivery | **0** extra physical/provider attempts for an already accepted/replayed logical outcome | Delivery owner; any duplicate is an immediate failure |
| Quota-held hot-loop amplification | **0** physical attempts while admission remains quota-blocked | Delivery owner; any attempt while held is an immediate failure |
| Concurrency saturation | admitted work must not exceed configured global/workspace capacity; excess work must enter explicit backpressure | Delivery owner; overflow without backpressure is an immediate failure |
| Saturation recovery | blocked backlog must drain after capacity is released without losing durable operations | Delivery owner; stranded/lost work is an immediate failure |
| Redis capacity recovery | expired/lost leases must become reusable without duplicate reservation | Delivery owner; leaked capacity or duplicate reservation is an immediate failure |
| PostgreSQL contention recovery | serialized mutations must preserve durable state and rollback/replay must remain duplicate-safe | Delivery owner; state loss or duplicate replay is an immediate failure |
| Ambiguous provider outcome | must remain reconciliation-held until evidence-backed resolution | Delivery owner; blind retry/terminal success without evidence is an immediate failure |
| Telemetry isolation/redaction | no cross-workspace access and no sensitive delivery material in operational snapshots | Security + Delivery owners; any leak is an immediate failure |

## Environment-sensitive SLO gates

Approval provenance:
- threshold set: `task0024-v7-exact-human-approved-20260915`
- approved by: `wpessential` as Delivery owner
- approved at: `2026-09-15T02:20:28+05:00`
- approval text: `Approve TASK-0024 exact v7 thresholds`
- benchmark source: `dab9b2002770c45fb9543b733d67e868bdb97d93`
- delivery evidence fingerprint: `3d634f3da23ca069469ec554b0c72746f12d6cdae9fbec7fd72080cad5ff8041`
- reconciliation evidence fingerprint: `01c8b45f929cf44d63926185820ec53977ddf325df740dbb746e3a75f878ba95`

| Gate | Threshold | State / decision rule |
|---|---:|---|
| Queue age p95 | `<= 2115.5879497528076 ms` | approved maximum; measured TASK-0024 v7 delivery p95 must not exceed this value |
| Queue age p99 | `<= 2862.8649711608887 ms` | approved maximum; measured TASK-0024 v7 delivery p99 must not exceed this value |
| End-to-end p95 | `<= 2242.6178455352783 ms` | approved maximum; internal benchmark only, not an external provider/network latency claim |
| End-to-end p99 | `<= 2987.617015838623 ms` | approved maximum; internal benchmark only, not an external provider/network latency claim |
| Sustainable throughput | `>= 7.255834504860022 ops/s` | approved minimum from the delivery benchmark repeated-run minimum |
| Reconciliation lag p95 | `<= 52.111148834228516 ms` | approved maximum from the ambiguity/reconciliation workload |
| Reconciliation lag p99 | `<= 64.10813331604004 ms` | approved maximum from the ambiguity/reconciliation workload |

`tools/delivery_slo_gate.py` remains the canonical numeric evaluator for supported regression evidence, and `tools/task0024_certification_gate.py` is the PHASE-04 final evaluator that binds these approved thresholds to the committed v7 evidence revisions and measured source. A threshold violation returns failure, and missing/malformed evidence returns an error; neither condition is a pass.

## TASK-0023 completion rule

TASK-0023 establishes the workload, production-parity fault/saturation coverage, deterministic regression invariants, telemetry safety boundary, and fail-closed numeric gate evaluator. Its completion **does not by itself certify PHASE-04 performance or authorize an external-provider scale/latency claim**.

TASK-0024 must run PHASE-04-wide clean-checkout certification against the approved threshold manifest and committed v7 evidence on a descendant acceptance head. Any missing approved revision, threshold violation, source-ancestry failure, incomplete evidence, or failed exact-head application/security/continuity/release gate blocks TASK-0024/PHASE-04 completion.
