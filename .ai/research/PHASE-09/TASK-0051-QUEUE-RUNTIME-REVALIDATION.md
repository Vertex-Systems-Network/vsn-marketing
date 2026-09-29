# TASK-0051 queue runtime revalidation — 2026-09-29

## Scope and authoritative evidence

The existing [TASK-0048 research](TASK-0048-RESEARCH.md) defines the pinned graph, durable waits, idempotent node attempts and action gates. This revalidation concerns the missing queue worker needed before RBT-052 can claim a representative end-to-end execution sample.

- Laravel 12 [Queues](https://laravel.com/docs/12.x/queues): Redis workers reserve jobs with `retry_after`; the worker timeout must be shorter than retry visibility. Dispatch with `after_commit` prevents a worker from observing uncommitted execution rows. Unique jobs depend on a shared atomic lock and do not by themselves replace database idempotency.
- Repository `config/queue.php` already selects Redis, sets `retry_after=120`, and sets `after_commit=true`. The deterministic execution and node-attempt keys with PostgreSQL lease fencing remain the source of truth; the Redis job is only a wake-up signal.

## Classification

`CONFIRMS_PLAN`: Implement a bounded graph router and workspace-scoped worker on the existing queue connection. Record enqueue and start instants from the real worker path. Keep action policy checks fresh immediately before side effects and preserve unknown outcomes for operator review. Do not infer production limits from the synthetic RBT-052 PostgreSQL-only baseline.

`BLOCKER`: Until the real worker, graph traversal, timer resume, and queue-age measurement are exercised in a source-pinned capture, TASK-0051 AC-5 remains pending and PHASE-10 stays inactive.

## Canonical policy measurement extension — 2026-09-29

The v2 synthetic adapter returned constant `consent=true` and `suppression_clear=true`, so neither the repository policy lookup nor a denial path was measured. The v3 isolated fixture appends granted consent through the canonical append-only record repository, evaluates effective consent and suppression through their existing services for every action, and probes missing consent plus canonical unsubscribe suppression on the same Redis graph path. The benchmark still uses a synthetic provider capability, authorization/quota booleans and no-op action. Classify this as `PARTIAL_EVIDENCE`: a measured canonical consent/suppression decision with fail-closed probes, not production action policy or provider latency. Keep AC-5 blocked until the remaining production boundary and full-path fault/scale evidence are independently reviewed.

## Full-path fault probes — 2026-09-29

V4 isolated harness adds duplicate Redis wake-up, foreign-workspace work ID, and cancellation-before-worker stale wake-up to the same five-node pinned execution path. It checks exact persisted node/attempt count and cancelled execution/work state after real workers. These probes extend reliability coverage; they do not constitute saturation, outage/retry, real provider side-effect, or production capacity evidence. The v4 validator requires the three named probe results and retains v2/v3 backward validation.

## Redis backlog stress — 2026-09-29

V5 records the Redis queue depth after enqueue and before workers for each sample run and adds a 200-operation/eight-worker stress run on the same 2-CPU app container and two-workspace graph. The validator requires at least 200 queued items and all 200 completed raw samples. This tests bounded backlog recovery and latency under increased load; it is not proof of CPU/memory saturation because the PostgreSQL/Redis service containers share the hosted runner and no continuous resource telemetry or production workload mix is captured. Do not infer a production SLO from it.

## Stress result and bounded comparison — 2026-09-29

V5 run 36554880080 showed 200 queued jobs/eight workers on the two-CPU app container fell to 1.918 ops/s with 65.865s p95 queue age, versus 100 jobs/four workers at about 4.86–4.87 ops/s and 2.3s p95. The combined change prevents attributing cause. V6 adds a 200-job/four-worker control on the same source and resource profile; review raw samples before tuning. No performance threshold or production capacity is approved.
