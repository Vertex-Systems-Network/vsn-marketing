# TASK-0051 queue runtime revalidation — 2026-09-29

## Scope and authoritative evidence

The existing [TASK-0048 research](TASK-0048-RESEARCH.md) defines the pinned graph, durable waits, idempotent node attempts and action gates. This revalidation concerns the missing queue worker needed before RBT-052 can claim a representative end-to-end execution sample.

- Laravel 12 [Queues](https://laravel.com/docs/12.x/queues): Redis workers reserve jobs with `retry_after`; the worker timeout must be shorter than retry visibility. Dispatch with `after_commit` prevents a worker from observing uncommitted execution rows. Unique jobs depend on a shared atomic lock and do not by themselves replace database idempotency.
- Repository `config/queue.php` already selects Redis, sets `retry_after=120`, and sets `after_commit=true`. The deterministic execution and node-attempt keys with PostgreSQL lease fencing remain the source of truth; the Redis job is only a wake-up signal.

## Classification

`CONFIRMS_PLAN`: Implement a bounded graph router and workspace-scoped worker on the existing queue connection. Record enqueue and start instants from the real worker path. Keep action policy checks fresh immediately before side effects and preserve unknown outcomes for operator review. Do not infer production limits from the synthetic RBT-052 PostgreSQL-only baseline.

`BLOCKER`: Until the real worker, graph traversal, timer resume, and queue-age measurement are exercised in a source-pinned capture, TASK-0051 AC-5 remains pending and PHASE-10 stays inactive.
