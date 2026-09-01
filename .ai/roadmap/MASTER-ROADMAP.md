# Master Roadmap

Phases are ordered. A later phase cannot become active until prior phase exit gates are satisfied or an approved ADR explicitly changes sequencing.

| Phase | Weight | Purpose |
|---|---:|---|
| PHASE-00 | 4 | Constitution, architecture, AI continuity, stack decision |
| PHASE-01 | 7 | Platform foundation: auth, tenancy, RBAC, audit, secrets, queues |
| PHASE-02 | 7 | Customer data core: contacts, identity, lists, consent, events |
| PHASE-03 | 7 | Research-backed provider/channel framework, security baseline, initial adapters |
| PHASE-04 | 7 | Delivery engine: queue, throttle, idempotency, retry, routing, failover, SLOs |
| PHASE-05 | 6 | Domains, sender identity, suppression, deliverability controls |
| PHASE-06 | 6 | Canonical content/template/creative/asset studio and provider sync |
| PHASE-07 | 7 | Campaign/publishing engine, approvals, unified calendar, scheduling, snapshots |
| PHASE-08 | 5 | Segmentation and natural-language-to-validated-segment compiler |
| PHASE-09 | 7 | Journey/automation engine |
| PHASE-10 | 8 | AI gateway, context/memory/tool policy, specialized marketing agents, AI red-team |
| PHASE-11 | 5 | Experimentation and adaptive optimization |
| PHASE-12 | 6 | Analytics, funnels, attribution, revenue/LTV, data quality |
| PHASE-13 | 5 | Omnichannel + prioritized social publishing/community/listening adapters |
| PHASE-14 | 5 | AI Connector Factory |
| PHASE-15 | 4 | Bounded autonomous marketing loops |
| PHASE-16 | 4 | Enterprise: SSO, SCIM, governance, residency, white-label, billing, DR |

Weights sum to 100 and are used for roadmap-level progress only after each phase publishes measurable exit criteria.

## Full preplanning rule

The minimum known future implementation skeleton is preplanned in `.ai/roadmap/PREPLANNED-IMPLEMENTATION-PLAN.md`, currently reserving TASK-0013 through TASK-0100.

The preplan exists so future AI sessions do not invent the product direction from chat context. It is a minimum known plan, not a claim that future external APIs, regulations, model capabilities, or provider policies are already understood.

Machine-readable task files and exact acceptance criteria are materialized/reconciled through the canonical continuity process before work becomes active. No reserved future task is executable merely because it appears in the long-horizon document.

## Research-first extension rule

Every applicable new subsystem/provider/channel/API/AI capability must follow `.ai/11-RESEARCH-FIRST-STANDARD.md` before implementation.

Research must use current authoritative internet/official documentation and may extend the preplan by adding justified prerequisites, acceptance criteria, tasks, capability requirements, or proposed ADRs. Research may not silently delete, weaken, reorder, renumber completed history, or reinterpret existing requirements.

When current external reality conflicts with the preplan, stop implementation and reconcile the plan first.

## Quality engineering rule

Every phase evaluates applicable `.ai/12-QUALITY-ENGINEERING-GATES.md` controls. Security, tenant isolation, privacy, supply chain, production parity, performance/SLO, reliability, accessibility, UI/UX, AI safety, data quality, provider contracts, backup/restore, and disaster recovery are planned product obligations rather than optional post-release cleanup.

A phase cannot close merely because feature code exists.

## Parallel execution rule

Phase/task sequencing remains canonical even when implementation is parallelized. The active top-level task may be decomposed into Supervisor-controlled workstreams under `.ai/13-PARALLEL-DEVELOPMENT.md`; parallel work does not make later roadmap tasks active. The Supervisor creates every declared branch first, assigns one agent/worktree/write scope per lane, owns shared state/contracts/migrations and all merges, processes `Work Done and Submitted` submissions as interrupts, broadcasts every merge, and requires all active branches to synchronize latest `main` before resuming.

This rule is reusable across all later phases whenever independent modules/capabilities provide safe path-disjoint work.

## Sequence principle

Foundation → Data → Provider Abstraction → Delivery → Deliverability → Content/Creative → Campaigns/Publishing → Segmentation → Journeys → AI → Optimization → Analytics → Omnichannel/Social → Connector Factory → Autonomous Marketing → Enterprise.

The phase order remains stable unless an approved ADR explicitly changes it.