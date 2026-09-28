# PHASE-09 — Journey and Automation Engine

Status: `in_progress`  
Progress: `55.00%` (TASK-0048 through TASK-0050 complete)
Roadmap progress: `59.85%` (deterministic task-weight calculation)
Active task: `TASK-0051`
Protected main anchor: `fe450e2d0c80dab8fa63882859618e9c0618f7a4`

## Scope

Build a deterministic, versioned, tenant-safe journey engine over canonical events, pinned segments, provider capabilities, consent, and suppression policy. PHASE-09 ends at certification in TASK-0053.

## Ordered tasks

- TASK-0048 — research and benchmark journey/automation patterns — **complete**
- TASK-0049 — versioned journey graph, node registry, validation, enrollment — **complete**
- TASK-0050 — triggers, waits, conditions, branches, actions, goals, exits, re-entry — **complete**
- TASK-0051 — concurrency, idempotency, retries, cancellation, replay, recovery — **in progress**
- TASK-0052 — builder, simulator, validation UX, execution timeline — ready
- TASK-0053 — PHASE-09 certification — ready

All executable behavior must be registered and deterministic. Journey executions pin an immutable version, enforce workspace scope independently of graph input, and re-check consent/suppression before side effects.

## Dedicated RBT-052 closeout

Before TASK-0051 AC-5 and PHASE-09 certification, execute the user-authorized RBT-052 dedicated batch on a verified isolated non-production PostgreSQL/Redis runtime. Pin exact source, preserve raw samples and digest, review coverage and failures, and accept only representative evidence. If queue or graph traversal is required for a representative journey claim, implement and measure those paths first. The unrelated Runner backlog remains deferred to the project-end coordinated batch. No PHASE-10 implementation begins before PHASE-09 certification.

## Explicit PHASE-10 boundary

PHASE-10 remains `planned` and `inactive`. TASK-0054 through TASK-0061, AI-agent/gateway architecture, and PHASE-10 implementation are not materialized or touched by this phase. Any future PHASE-10 work requires its own research-first authorization and transition.
