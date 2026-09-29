# TASK-0051 Journey Execution Benchmark Environment

## Scope and authority

RBT-052 is the representative execution measurement for TASK-0051 AC-5. Its registration does not authorize a run. Execute it in the coordinated project-end Runner batch after explicit runtime authority and an isolated, non-production PostgreSQL/Redis environment are available. Required exact-head correctness and security CI remain separate. Do not infer production numeric limits or provider latency from CI duration or this internal workload.

This document freezes the capture contract. The journey-specific capture harness is `tools/task0051_benchmark_capture.php`, with structural validator `tools/task0051_benchmark_evidence.py`. Its reviewed source must be pinned before execution. It has not been run in an authorized external environment, so it is not TASK-0051 AC-5 evidence.

## Source and environment preflight

1. Pin a full 40-character commit SHA containing the reviewed harness and this contract. Record the actual runtime source identity from a real Git checkout or immutable deployment metadata and verify equality to the explicit selected SHA. A synthetic Git ref is invalid.
2. Use dedicated private PostgreSQL and Redis instances and a visibly named benchmark/test database. Confirm the runtime uses PostgreSQL, Redis, the intended migrations, `APP_ENV=benchmark`, and no production or shared customer endpoint. Record PHP, PostgreSQL, Redis, container/image, CPU, memory, storage, worker count and configuration revisions.
3. Record the database identity, benchmark run ID, UTC start/end times, source SHA, fixture revision, graph hash, pseudonymous workspace identifiers and resource limits. Do not record credentials, raw recipients, provider tokens or private payloads.
4. Run the non-destructive preflight first with `php tools/task0051_benchmark_preflight.php --commit-sha=<exact-source-sha> --database=<dedicated-database> --ack=I_ACKNOWLEDGE_DEDICATED_NON_PRODUCTION_BENCHMARK_ENVIRONMENT`. This command reports environment identity and explicitly does not collect benchmark evidence. Then run the reviewed capture harness's own preflight. It must reject missing or mismatched source identity, production/shared database names, missing explicit acknowledgement, unsupported drivers, invalid fixture/budget inputs and an existing output path. Do not use `migrate:fresh`, `FLUSHDB`, `FLUSHALL` or automatic execution at deployment startup.

## Fixture and workload

Freeze the fixture before measurement. It must state a reproducible seed, graph node/edge counts and fan-out, node-type mix, enrollment count and idempotency-key distribution, workspace count, configured per-workspace enrollment and attempt caps, lease length, retry budget/delay, worker concurrency, warmup, measurement duration, run count and fault schedule. Include at least two workspaces to detect cross-workspace interference. Use synthetic subjects and stubbed provider actions; external provider/network latency is outside this measurement.

Measure normal claim/complete, duplicate claim or redelivery, capacity saturation and release, expired lease/reclaim with stale-token fencing, retryable and unknown-outcome failure, dead-letter/operator review, cancellation with late completion, and authorized replay on its pinned journey version. Vary only one declared factor per comparison. Keep warmup samples separate from measured samples and repeat a fixed fixture; report failed and censored runs rather than dropping them.


## Capture command for the authorized end batch

After source, environment and resource identity are frozen, run the reviewed harness manually on the dedicated runtime. This is a future operator command, not authorization to execute it now:

```bash
php tools/task0051_benchmark_capture.php \\
  --benchmark-id=task0051-journey-prodrep-01 \\
  --commit-sha="$TASK0051_BENCHMARK_SOURCE_SHA" \\
  --database="$DB_DATABASE" \\
  --resource-profile=<reviewed-resource-id> \\
  --runner-image-sha=<exact-64-character-image-digest> \\
  --cpu-count=<allocated-cpu-count> \\
  --memory-mib=<allocated-memory-mib> \\
  --runs=2 --operations=100 --concurrency=4 --seed=51 \\
  --output=/workspace/task0051-journey-evidence.json \\
  --ack=I_ACKNOWLEDGE_DEDICATED_NON_PRODUCTION_BENCHMARK_ENVIRONMENT
python3 tools/task0051_benchmark_evidence.py /workspace/task0051-journey-evidence.json
sha256sum /workspace/task0051-journey-evidence.json
```

The harness first invokes the read-only preflight. It creates uniquely named synthetic rows without a database reset, performs a separate 20-operation warmup, then repeats measured runs. Parallel workers use separate PHP processes against PostgreSQL. Evidence includes raw enrollment and node-attempt durations, nearest-rank p50/p95/p99, throughput, fixture graph hash and resource identity, plus persisted saturation, lease, retry, dead-letter, unknown-outcome, cancellation, tenant-scope and authorized replay checks. The evidence validator checks raw sample counts, source identity, fixture consistency, percentiles and outcome invariants before an exclusive output publication. A capture failure is not a passing benchmark.

The current workload measures application-service enrollment and PostgreSQL attempt persistence, with concurrent workers and synthetic provider actions. Its elapsed window starts before enrollment and ends after concurrent attempt workers finish; throughput covers both, while per-operation attempt end-to-end duration covers only claim through completion. Fault checks and persisted verification happen outside that timed window. Redis is checked for health only. There is no journey Redis queue worker or full graph traversal orchestrator in the current TASK-0051 runtime, so this capture explicitly marks both as unmeasured. It cannot alone establish queue age, traversal end-to-end latency, or a production numeric SLO. Before accepting AC-5, review the captured evidence against this implementation scope; if representative execution requires those paths, implement and measure them in a separate reviewed workload first.

## Isolated GitHub Actions baseline

The manual or dedicated `benchmark/rbt052-run` branch trigger in `.github/workflows/rbt052-dedicated-capture.yml` provisions fresh per-job PostgreSQL and Redis services with synthetic credentials, builds a content-addressed benchmark-only PHP/Python image, pins the checkout to the run SHA, and uploads raw JSON plus SHA-256 sidecar. The bounded app container has two CPUs and 4096 MiB; service containers share the GitHub host and are outside that app container limit. The workflow uses a fixed 100-operation, two-run fixture with four worker processes and a separate warmup. Its run ID, commit, image ID, service versions, workflow SHA and artifact digest must be reviewed together. This provides real measurements for that ephemeral environment, not an automatic production-representative SLO. The reviewer must still decide whether queue/graph coverage and a more representative runtime are needed before AC-5.

The initial isolated run [#36416121193](https://github.com/Vertex-Systems-Network/vsn-marketing/actions/runs/36416121193) generated and structurally validated samples, but artifact upload failed with file permissions. It is a failed attempt, not immutable evidence or AC-5 acceptance. A corrected fresh run must publish readable raw JSON and digest before review.

Run [#36504122827](https://github.com/Vertex-Systems-Network/vsn-marketing/actions/runs/36504122827) published a valid isolated synthetic baseline; see [its raw evidence and review](../benchmarks/evidence/RBT-052-36504122827-review.md). The reviewed capture does not include Redis queue latency or complete graph traversal, so TASK-0051 AC-5 remains blocked. A new source-pinned capture with those paths and a representative resource review is required before acceptance.

## Capture and acceptance

The reviewed harness must preserve raw per-operation durations and outcomes as well as run-level counts: enrollment accepted/rejected/duplicate; attempt claimed/duplicate/saturated/reclaimed/completed; stale completion refused; retry/dead-letter/operator-review; cancellation/late completion; replay duplicate/pinned-version; queue age and end-to-end duration where observable; throughput and the measurement window. Record p50/p95/p99 with sample counts and the percentile method, resource/connection observations, and the exact configuration and fixture identity. Check invariants against persisted PostgreSQL transition and attempt state, including no cap overshoot and no cross-workspace data access. Redis health and queue behavior must be included only when the workload actually uses them.

Publish an immutable evidence document with its cryptographic digest and source/fixture/run IDs. Link the real capture output, reviewer, and runtime authority to RBT-052; set its exact source SHA and terminal status only after a genuine run and validation. TASK-0051 AC-5 can then be reviewed against the evidence. Keep production numeric thresholds unset until their separate approval process is completed.

## Open prerequisite

The harness and read-only preflight exist, but no authorized external runtime execution or reviewed evidence is currently recorded for RBT-052. This runbook is preparatory; it is not benchmark evidence and does not unblock TASK-0051.
