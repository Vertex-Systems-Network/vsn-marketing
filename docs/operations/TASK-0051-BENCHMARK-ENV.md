# TASK-0051 Journey Execution Benchmark Environment

## Scope and authority

RBT-052 is the representative execution measurement for TASK-0051 AC-5. Its registration does not authorize a run. Execute it in the coordinated project-end Runner batch after explicit runtime authority and an isolated, non-production PostgreSQL/Redis environment are available. Required exact-head correctness and security CI remain separate. Do not infer production numeric limits or provider latency from CI duration or this internal workload.

This document freezes the capture contract. The journey-specific capture harness is still required, must be reviewed on the exact measured source, and must fail closed before the benchmark is run. A command or evidence location is intentionally not invented here.

## Source and environment preflight

1. Pin a full 40-character commit SHA containing the reviewed harness and this contract. Record the actual runtime source identity from a real Git checkout or immutable deployment metadata and verify equality to the explicit selected SHA. A synthetic Git ref is invalid.
2. Use dedicated private PostgreSQL and Redis instances and a visibly named benchmark/test database. Confirm the runtime uses PostgreSQL, Redis, the intended migrations, `APP_ENV=benchmark`, and no production or shared customer endpoint. Record PHP, PostgreSQL, Redis, container/image, CPU, memory, storage, worker count and configuration revisions.
3. Record the database identity, benchmark run ID, UTC start/end times, source SHA, fixture revision, graph hash, pseudonymous workspace identifiers and resource limits. Do not record credentials, raw recipients, provider tokens or private payloads.
4. Run the reviewed harness's non-destructive preflight first. It must reject missing or mismatched source identity, production/shared database names, missing explicit acknowledgement, unsupported drivers, invalid fixture/budget inputs and an existing output path. Do not use `migrate:fresh`, `FLUSHDB`, `FLUSHALL` or automatic execution at deployment startup.

## Fixture and workload

Freeze the fixture before measurement. It must state a reproducible seed, graph node/edge counts and fan-out, node-type mix, enrollment count and idempotency-key distribution, workspace count, configured per-workspace enrollment and attempt caps, lease length, retry budget/delay, worker concurrency, warmup, measurement duration, run count and fault schedule. Include at least two workspaces to detect cross-workspace interference. Use synthetic subjects and stubbed provider actions; external provider/network latency is outside this measurement.

Measure normal claim/complete, duplicate claim or redelivery, capacity saturation and release, expired lease/reclaim with stale-token fencing, retryable and unknown-outcome failure, dead-letter/operator review, cancellation with late completion, and authorized replay on its pinned journey version. Vary only one declared factor per comparison. Keep warmup samples separate from measured samples and repeat a fixed fixture; report failed and censored runs rather than dropping them.

## Capture and acceptance

The reviewed harness must preserve raw per-operation durations and outcomes as well as run-level counts: enrollment accepted/rejected/duplicate; attempt claimed/duplicate/saturated/reclaimed/completed; stale completion refused; retry/dead-letter/operator-review; cancellation/late completion; replay duplicate/pinned-version; queue age and end-to-end duration where observable; throughput and the measurement window. Record p50/p95/p99 with sample counts and the percentile method, resource/connection observations, and the exact configuration and fixture identity. Check invariants against persisted PostgreSQL transition and attempt state, including no cap overshoot and no cross-workspace data access. Redis health and queue behavior must be included only when the workload actually uses them.

Publish an immutable evidence document with its cryptographic digest and source/fixture/run IDs. Link the real capture output, reviewer, and runtime authority to RBT-052; set its exact source SHA and terminal status only after a genuine run and validation. TASK-0051 AC-5 can then be reviewed against the evidence. Keep production numeric thresholds unset until their separate approval process is completed.

## Open prerequisite

No journey-specific reviewed capture harness or authorized external runtime is currently recorded for RBT-052. This runbook is preparatory; it is not benchmark evidence and does not unblock TASK-0051.
