# Last Checkpoint

## State

- Timestamp: `2026-09-22T22:15:00Z`
- Observed main: `8218f3772f7ab131dbee3927c13970af5e1e8299`
- Active issue: `none`
- Active PR: `363`
- Active branch: `task/0039-due-claim-execution-intent`
- Current milestone: `TASK-0039-DUE-CLAIM-EXECUTION-INTENT`
- Milestone status: `VERIFYING`
- Active task: `TASK-0039`
- Next task: `none`
- Current phase: `PHASE-07`
- Execution status: `in_progress`
- Pending Runner IDs: `RBT-023`
- Blocked Runner IDs: `RBT-004`
- State fingerprint: `25ab44bc2ccaf587ba8dd894a765a59400e87e8bb5284e1e497f84dcc425134e`

## Completed / observed this session

TASK-0039 AC-5 approval-timing/missed-occurrence history was terminally reconciled through PR #362 on protected main `8218f3772f7ab131dbee3927c13970af5e1e8299`. Exact reconciliation source `6003c14c1ac942a3e9481120bcd5f0385bf70924` passed AI Continuity Guard `35789527404`, Application Foundation CI `35789527241` including PostgreSQL/Redis integration, and Security Supply Chain CI `35789527189`. The reconciliation also rolled immutable journal events 99-108 into the bounded archive without changing historical bytes.

PR #363 stages the bounded AC-6 product milestone. PostgreSQL schedule-row locking is the canonical serialization point; durable due claims pin exact workspace/campaign/snapshot/schedule/schedule-hash/scheduled approval/evaluated approval evidence. Raw lease tokens are never persisted: only SHA-256 digests are stored. Active duplicate workers fail closed, expired leases require a fresh token and advance attempt/version lineage, and claim identity plus monotonic transitions are database-guarded.

An exact due occurrence reuses the canonical approval evaluator before first claim. Pre-due work is rejected. A late unclaimed occurrence is routed into the trusted AC-5 missed/needs-reschedule ledger. After due claiming starts, backdated reschedule/cancel and missed-outcome competitors fail closed. Campaign lifecycle is rechecked before emission.

Under a current valid lease, PR #363 creates at most one immutable execution intent per workspace/schedule and one FK-bound outbox handoff in the same database transaction. Injected outbox failure must roll back intent/emitted state, while replay after commit returns the canonical intent. Real PostgreSQL multi-process contention coverage launches duplicate claim and emit workers and requires one claim, one immutable intent and one outbox record.

TASK-0039 remains in progress at roadmap `48.45%` / PHASE-07 `63.64%`. No provider-native scheduling side effect, live provider publication, media upload, provider credential use, TASK-0040, deployment/release authority or deferred Runner optimization is activated.

## Tests

RBT-023 exact-head Continuity/Application/Security verification is pending. Merge-blocking coverage includes backend/architecture/static/formatting, PHP 8.3 compatibility, E2E, PostgreSQL/Redis integration, PostgreSQL multi-process scheduler contention, lease hashing/stale-token behavior, terminal-history conflicts, lifecycle cancellation, cross-workspace isolation, rollback/retry atomicity and security/supply-chain checks.

## Blockers

- None

## Exact next action

Perform exact-head verification for PR #363. Merge the TASK-0039 AC-6 due-claim/execution-intent milestone only if AI Continuity Guard, Application Foundation CI and Security Supply Chain CI are green on the unchanged head; review is clean; migration/data-safety checks pass; PostgreSQL multi-process contention proves duplicate claim/emit workers converge to one claim, one immutable execution intent and one durable outbox handoff; raw lease tokens are never persisted; pre-due, foreign-workspace, active-lease, stale-token, terminal-history and cancelled-lifecycle paths fail closed; expired leases recover with fresh token/version lineage; and injected partial outbox failure rolls back intent/emitted state before replay succeeds exactly once. After trusted merge, terminally reconcile AC-6 before starting the next TASK-0039 acceptance slice. Keep provider-native scheduling, live provider publication, media upload, provider credentials, TASK-0040, deployment/release authority and deferred Runner optimization inactive.
