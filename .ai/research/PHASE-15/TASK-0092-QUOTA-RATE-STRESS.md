# TASK-0092 — Multi-Worker Quota / Rate Stress (Staged)

Status: **independent AC-2 test candidate**, staged separately while PR #566 is being certified; NOT a completed acceptance criterion.

## Why this test is needed

Existing PostgreSQL integration tests prove two-worker contention for offline quota reservation and a stop-versus-admission race. TASK-0092 AC-2 requires a broader adversarial stress case: several independent processes simultaneously asking for the same finite quota and per-minute rate authority, with no fabricated provider, billing, or send success.

## Deterministic four-worker experiment

- Create real isolated PostgreSQL organization/workspace and current global stop, workspace quota and rate-window rows. Quota permits **one** action, one attempt, 60 tokens, two volume units and 11 minor cost units; rate permits one attempt per minute.
- Purge any inherited PostgreSQL connection before forking; spawn four independent workers, each submitting a *distinct immutable run* bound to the same workspace/policy.
- Release start signals to all four sockets, requiring exactly **one** offline reservation and three denied/held outcomes. Reject any outcome granting `execution_authorized` or `promotion_authorized`.
- Re-read the durable PostgreSQL counters: one reservation, one action, 60 tokens, two volume, one attempt, 11 **reserved** cost minor units, zero **spent** cost, one rate-window admission. No provider call, email, queue, external bill, or live traffic occurs.
- Apply explicit bounded read timeouts, child exit checks and database cleanup even on failed assertions.

## Integrity and limits

The test is a stress **regression fixture** not a real-world capacity/latency benchmark. It does not prove queue failure recovery, a real provider settlement, zero dropped production messages, cross-region consistency or adversarial model injection. Those remain pending TASK-0092 AC-1/2/3.

Run only under `RUN_INFRA_INTEGRATION=true` with PostgreSQL, then require full exact-head Foundation/PHP 8.3/PostgreSQL/browser E2E/Security/Governance and protected-main checks before promoting any task evidence. No canonical progress or task acceptance status changes in this staged file.
