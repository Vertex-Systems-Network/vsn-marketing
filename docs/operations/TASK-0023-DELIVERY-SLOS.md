# TASK-0023 Delivery SLO Contract

Status: **in progress**  
Workstream: `WS-0023-SLO-CONTRACTS`  
Baseline: `57e50d88f008729b0a36268e650ce1e4694823ea`

This document defines the measurement contract for TASK-0023. It intentionally does **not** claim production capacity or fixed thresholds before repeatable PostgreSQL/Redis evidence exists.

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

1. Every result records commit SHA, PHP/Laravel version, PostgreSQL version, Redis version, runner resources, scenario seed, operation count, concurrency, and measurement window.
2. Warm-up samples are separated from measured samples.
3. Percentiles are computed from raw per-operation observations, not averages of averages.
4. Missing or malformed measurements fail closed; they never silently pass a gate.
5. Workspace/provider/channel breakdowns must not expose credentials, message bodies, recipient PII, or cross-workspace data.
6. Provider timeout, rate-limit, transient/permanent failure, Redis interruption/latency, PostgreSQL contention, worker termination, and recovery scenarios are measured separately from the steady-state baseline.
7. Thresholds become enforceable only after repeatable evidence is committed and reviewed. Until then they are marked `TBD_MEASURED`, not guessed.

## Candidate SLO gates

| Gate | Threshold | State |
|---|---|---|
| Queue age p95 | `TBD_MEASURED` | awaiting load evidence |
| Queue age p99 | `TBD_MEASURED` | awaiting load evidence |
| End-to-end p95 | `TBD_MEASURED` | awaiting load evidence |
| End-to-end p99 | `TBD_MEASURED` | awaiting load evidence |
| Duplicate provider attempt rate | `TBD_MEASURED` | awaiting race/recovery evidence |
| Recovery convergence | `TBD_MEASURED` | awaiting fault evidence |
| Retry amplification ceiling | `TBD_MEASURED` | awaiting provider-fault evidence |
| Saturation/backpressure bound | `TBD_MEASURED` | awaiting queue/resource evidence |

## Next evidence dependency

The load harness, PostgreSQL contention, Redis fault, provider-fault, queue-saturation, recovery/duplicate, observability, security-telemetry, and regression-gate workstreams must feed measured evidence into this contract before TASK-0023 acceptance can be marked complete.
