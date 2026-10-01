# PHASE-09 — Journey and Automation Engine

Status: `in_progress`  
Progress: `75.00%` (TASK-0048 through TASK-0051 complete)
Roadmap progress: `61.25%` (deterministic task-weight calculation)
Active task: `TASK-0052`
Protected main anchor: `0b1bc95c94b8b48ec63b5e812bd3996ba1e835a5`

## Scope

Build a deterministic, versioned, tenant-safe journey engine over canonical events, pinned segments, provider capabilities, consent, and suppression policy. PHASE-09 ends at certification in TASK-0053.

## Ordered tasks

- TASK-0048 — research and benchmark journey/automation patterns — **complete**
- TASK-0049 — versioned journey graph, node registry, validation, enrollment — **complete**
- TASK-0050 — triggers, waits, conditions, branches, actions, goals, exits, re-entry — **complete**
- TASK-0051 — concurrency, idempotency, retries, cancellation, replay, recovery — **complete**
- TASK-0052 — builder, simulator, validation UX, execution timeline — **in progress**
- TASK-0053 — PHASE-09 certification — ready

All executable behavior must be registered and deterministic. Journey executions pin an immutable version, enforce workspace scope independently of graph input, and re-check consent/suppression before side effects.

## Dedicated RBT-052 closeout

TASK-0051 AC-5 was accepted using RBT-052 v6 run `36556234322`, source `a7ef938d847f34b39793ea76b209c5a1eecf8e13`, raw artifact `11026993958`, and raw SHA-256 `a3cb89d66bb9177bf0b9629682d4faa543eb8ef067124e88e3d75f2bac9042e0`. The isolated harness exercised a real Redis queue, PostgreSQL-backed pinned five-node journey graph, and canonical consent/suppression gates; provider adapter was synthetic/no-op. The 200-job/four-worker control had 2.774s queue-age p95 over two passes; the eight-worker stress had 64.266s over five passes. These are harness observations only, not production limits or live-provider latency. PR #447 added bounded scheduled due-work recovery and merged to protected main. PHASE-09 remains open pending TASK-0052 and TASK-0053; PHASE-10 remains inactive.

## Explicit PHASE-10 boundary

PHASE-10 remains `planned` and `inactive`. TASK-0054 through TASK-0061, AI-agent/gateway architecture, and PHASE-10 implementation are not materialized or touched by this phase. Any future PHASE-10 work requires its own research-first authorization and transition.
