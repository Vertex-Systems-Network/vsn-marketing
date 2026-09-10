# AI-Native Parallel Plan — TASK-0023 Activation and Delivery SLO Handoff

Status: **active** — TASK-0023 is the canonical PHASE-04 successor. This control-plane slice transactionally closes TASK-0101, restores/registers the preserved TASK-0023 and TASK-0024 specifications, activates TASK-0023, and hands execution back to the delivery SLO/load/fault-injection roadmap without falsely claiming the performance work is already complete.

Supervisor: `supervisor-main`  
Supervisor branch: `supervisor/task-0023-delivery-slo`  
Trusted baseline: `c6dab8eff0e8284a1e39d3105429ba5931fec9da`  
Broadcast channel: GitHub issue #43  
Completion signal: `Work Done and Submitted`

The activation Supervisor branch and reserved QA branch were pre-created from trusted `main` `c6dab8eff0e8284a1e39d3105429ba5931fec9da` before TASK-0023 parallel planning mutations. That baseline contains the merged Persistent Supervisor and passed post-merge AI Continuity `34522847507`, Application Foundation `34522847451`, Security Supply Chain `34522847473`, Release Integrity `34522847562`, and OpenSSF Scorecard `34522847785`. Persistent Supervisor default-branch run `34523049920` passed and durable issue #102 reached `HEALTHY` with no blockers.

TASK-0101 is complete in this activation branch. TASK-0023 and TASK-0024 use their preserved preplanned PHASE-04 specifications. Their registered weights are 15 and 10 respectively, completing the PHASE-04 denominator after TASK-0019..TASK-0022 without changing product scope.

<!-- WORKSTREAM_TABLE_START -->
| Merge group | Workstream | Module/capability | Slot | Assigned agent | Start status | Branch | PR merge strategy | Resume/sync strategy |
|---:|---|---|---|---|---|---|---|---|
| 10 | WS-0023-ACTIVATION | Transactional TASK-0101 closeout, preserved TASK-0023/TASK-0024 registration, TASK-0023 activation, and parallel-control handoff | `occupied` | `supervisor-main` | `active` | `supervisor/task-0023-delivery-slo` | squash | merge latest main before resume |
| 20 | WS-0023-PERFORMANCE-QA | Reserved independent TASK-0023 performance/fault-injection evidence review capacity; must sync to activation main before any lease or write | **OPEN** | — | `awaiting_agent` | `agent/task-0023-performance-qa` | squash | merge latest main before resume |
<!-- WORKSTREAM_TABLE_END -->

## Activation acceptance

`WS-0023-ACTIVATION` is complete only when the repository proves all of the following on its exact PR head:

- TASK-0101 is completed with every acceptance criterion true and post-merge Persistent Supervisor evidence recorded;
- TASK-0023 is registered `ready`, TASK-0024 is registered `planned`, and canonical state names TASK-0023 active with TASK-0024 next;
- PHASE-04 progress deterministically recalculates to 75.00% and roadmap progress to 30.25%;
- task/state/checkpoint/journal mutations remain transactionally valid and append-only;
- the temporary activation workflow is absent from the final diff;
- parallel registry parent is TASK-0023, the activation Supervisor lane is exclusively leased, and the QA lane remains open/unassigned;
- exact-head AI Continuity, Application Foundation, and Security Supply Chain CI pass.

The standalone `Work Done and Submitted` signal on this activation PR means only the activation workstream above is complete. It does **not** mean TASK-0023 performance/SLO acceptance is complete.

## Persistent Supervisor remains standing infrastructure

`.github/workflows/persistent-supervisor.yml` remains the repository-native always-on coordination runtime. It combines event-driven reconciliation with the five-minute heartbeat, updates `[Supervisor] Persistent Control Plane Status`, verifies exact standalone completion signals, current-main ancestry and exact-head required CI, and never auto-merges or mutates canonical `.ai` or product code.

After this activation reaches `main`, the status issue should converge from any transient drift to a healthy TASK-0023 canonical state.

## TASK-0023 implementation after activation

After the activation merge, implementation must continue from fresh `main`, not from an unsynchronized stale branch. The Supervisor will establish a fresh implementation lane and revalidate current authoritative PostgreSQL, Redis, Laravel/PHP, and CI/runtime behavior before fixing performance thresholds.

The preserved TASK-0023 scope requires:

- explicit queue-age, throughput, success/error, saturation, reconciliation-lag and meaningful p95/p99 SLIs/SLOs;
- repeatable production-representative PostgreSQL/Redis normal, burst, quota-constrained and saturation workloads;
- worker termination, Redis interruption/latency, PostgreSQL contention, provider timeout/error/rate-limit fault injection;
- duplicate behavior, retry amplification, queue growth/backpressure, circuit-breaker, dead-letter/reconciliation recovery and resource saturation evidence;
- safe workspace/provider/channel hotspot telemetry;
- automated deterministic regression thresholds where stable;
- exact-head full application/security/continuity evidence before TASK-0023 completion.

No PHASE-05+ product capability is authorized by this task.

## Handoff sequence

1. Finish and merge `WS-0023-ACTIVATION` with exact-head green evidence.
2. Verify Persistent Supervisor issue #102 converges to `HEALTHY` on TASK-0023 main state.
3. Create/synchronize fresh TASK-0023 implementation branches from the new activation main before implementation-plan mutations.
4. Update the parallel registry/leases to the implementation cycle and keep QA unassigned unless explicitly onboarded.
5. Complete research-first revalidation and record `.ai/research/PHASE-04/TASK-0023-RESEARCH.md`.
6. Implement production-parity SLO/load/fault harnesses and evidence.
7. Complete TASK-0023 only after all acceptance criteria are measured/proven; then transactionally activate TASK-0024.

No external ChatGPT schedule is part of repository supervision.
