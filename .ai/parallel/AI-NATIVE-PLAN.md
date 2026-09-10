# AI-Native Parallel Plan — TASK-0023 Delivery SLO / Load / Fault Certification

Status: **active** — TASK-0023 is the canonical PHASE-04 task. It establishes measured delivery SLOs, production-representative PostgreSQL/Redis load and saturation evidence, fault-injection coverage, and deterministic regression thresholds before PHASE-04 certification.

Supervisor: `supervisor-main`  
Supervisor branch: `supervisor/task-0023-delivery-slo`  
Trusted baseline: `c6dab8eff0e8284a1e39d3105429ba5931fec9da`  
Broadcast channel: GitHub issue #43  
Completion signal: `Work Done and Submitted`

The TASK-0023 Supervisor branch and optional QA branch were pre-created from trusted `main` `c6dab8eff0e8284a1e39d3105429ba5931fec9da` before TASK-0023 parallel planning mutations. That baseline is the merged TASK-0101 Persistent Supervisor control plane and passed post-merge AI Continuity `34522847507`, Application Foundation `34522847451`, Security Supply Chain `34522847473`, Release Integrity `34522847562`, and OpenSSF Scorecard `34522847785`. Persistent Supervisor default-branch run `34523049920` passed and durable issue #102 reached `HEALTHY` with no blockers.

TASK-0101 is complete. The repository-native Persistent Supervisor remains standing infrastructure while product execution returns to the preserved roadmap. TASK-0023 has weight 15 and TASK-0024 has weight 10, completing the original PHASE-04 task-weight denominator of 100 without changing their preplanned scope.

<!-- WORKSTREAM_TABLE_START -->
| Merge group | Workstream | Module/capability | Slot | Assigned agent | Start status | Branch | PR merge strategy | Resume/sync strategy |
|---:|---|---|---|---|---|---|---|---|
| 10 | WS-0023-SUPERVISOR-INTEGRATION | TASK-0023 delivery SLO/load/fault-injection research, production-parity harness integration, threshold governance, canonical evidence, and acceptance | `occupied` | `supervisor-main` | `active` | `supervisor/task-0023-delivery-slo` | squash | merge latest main before resume |
| 20 | WS-0023-PERFORMANCE-QA | Independent TASK-0023 performance/fault-injection evidence review without shared-path mutation | **OPEN** | — | `awaiting_agent` | `agent/task-0023-performance-qa` | squash | merge latest main before resume |
<!-- WORKSTREAM_TABLE_END -->

## TASK-0023 scope

The Supervisor lane owns current-research reconciliation, test/harness design, shared CI workflow integration, canonical evidence, and final acceptance. The optional QA lane is intentionally unassigned and unleased; it exists only as conflict-safe independent verification capacity if a worker is explicitly onboarded.

Required product evidence includes:

- explicit SLIs/SLOs for queue age, throughput, meaningful p95/p99 latency, success/error classes, saturation and reconciliation lag;
- repeatable PostgreSQL/Redis workloads across normal, burst, quota-constrained and saturated conditions;
- fault injection for worker termination, Redis interruption/latency, PostgreSQL contention, provider timeout/error/rate-limit behavior and recovery;
- measured duplicate behavior, retry amplification, queue growth/backpressure, circuit-breaker behavior, dead-letter/reconciliation recovery and resource saturation;
- workspace/provider/channel hotspot telemetry without secret or cross-workspace leakage;
- automated deterministic regression thresholds where measurement is stable, with explicit evidence/decision rules for non-automated thresholds;
- exact-head application, integration, security, continuity and workflow-policy gates before acceptance.

TASK-0023 must not pull PHASE-05+ sender-domain, deliverability, campaign, content, journey, AI-agent or unrelated product work forward.

## Persistent Supervisor remains active infrastructure

`.github/workflows/persistent-supervisor.yml` remains the repository-native always-on coordination runtime. It combines event-driven reconciliation with the five-minute heartbeat, updates `[Supervisor] Persistent Control Plane Status`, validates exact standalone `Work Done and Submitted` submissions, checks current-main ancestry and exact-head required CI, and never auto-merges or mutates canonical product/state files.

The Persistent Supervisor observes TASK-0023 as soon as this activation/control-plane PR reaches `main`. Its status issue is expected to transiently report drift while main and a newly staged cycle differ, then converge after the canonical transition is merged and main gates finish.

## Execution sequence

1. Merge this TASK-0023 activation/control-plane change only after exact-head AI Continuity, Application Foundation and Security Supply Chain gates pass.
2. Reconfirm Persistent Supervisor issue #102 converges to `HEALTHY` on the new TASK-0023 main state.
3. Perform research-first revalidation against current PostgreSQL, Redis, Laravel/PHP and GitHub Actions/runtime behavior before fixing load or fault thresholds.
4. Record research in `.ai/research/PHASE-04/TASK-0023-RESEARCH.md`; do not guess production-performance contracts.
5. Implement a deterministic/repeatable load and fault-injection harness using production-representative PostgreSQL/Redis services and bounded CI workloads.
6. Record environment assumptions and measured outputs in `docs/verification/TASK-0023-DELIVERY-SLO.md`.
7. Automate stable regression thresholds and retain fail-closed behavior for duplicate/recovery evidence.
8. Keep the QA slot open unless explicitly assigned through the standard onboarding/lease flow.
9. When all TASK-0023 acceptance criteria are proven on an exact head, submit the Supervisor PR with `Workstream: WS-0023-SUPERVISOR-INTEGRATION` and standalone `Work Done and Submitted`.
10. After merged post-main certification, transactionally complete TASK-0023 and activate already-registered TASK-0024 for final PHASE-04 certification.

No external ChatGPT schedule is part of repository supervision.
