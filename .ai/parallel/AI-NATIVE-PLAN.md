# AI-Native Plan — 5-Hour Continuous Workspace Development

Status: **CONTINUOUS BATCH MODE ENABLED**

Supervisor: `supervisor-main`  
Protected branch: `main`  
Continuous batch contract: `.ai/parallel/WORKSPACE-5H-CONTINUOUS-BATCH.md`  
Default mutating Workspace envelope: **300 minutes / available Workspace credits**  
Merge strategy: `squash` unless a stricter repository rule applies  
Security posture: fail closed

## Purpose

The AI-Native flow is no longer a sequence of chat-sized micro-milestones. A mutating Workspace start/resume runs one continuous development batch and keeps advancing the accepted repository work path across task and phase boundaries until the Workspace credit window ends, the canonical roadmap has no safe dependency-ready work, or a genuine human-only/safety blocker is the sole remaining path. Generic start/continue/resume defaults to maximum safe canonical roadmap-frontier progress for the available credits; an explicitly narrower PR/task/phase/audit scope remains narrow.

The detailed execution contract is `.ai/parallel/WORKSPACE-5H-CONTINUOUS-BATCH.md`. Every Supervisor/Workspace agent MUST read that contract before writable work.

## Mandatory start/resume order

1. recover interrupted transactions and validate continuity;
2. read `CURRENT-STATE.yaml` and `LAST-CHECKPOINT.md`;
3. resolve exact live protected `main`;
4. reconcile open Issues first, then open PRs;
5. reconcile accepted work queue, deterministic claims, and Runner Benchmark;
6. identify stale/duplicate carriers and choose the authoritative work path;
7. enter the continuous execution loop without asking for another routine consent.

Repository/runtime evidence outranks stale plan prose. If this file's audit snapshot is stale, update behavior from live repository evidence rather than pausing for the user.

## Continuous execution behavior

Inside the started batch, automatically perform:

`implementation -> focused tests -> PR/update -> CI diagnosis -> bounded same-scope repair -> exact-head verification -> merge -> post-merge reconciliation -> next dependency-ready task -> next dependency-ready phase -> repeat until credit/window exhaustion or hard boundary`

Do not stop or ask the user at validator failures, formatting/type/lint/test failures, ordinary CI failures, stale branches, duplicate PRs, merge conflicts, dependency conflicts, README/progress drift, transient connector/tool failures, or a required same-scope repair. Resolve them, use documented fallbacks, and continue.

Do not emit next-action options at internal batch boundaries. The next-action UI/numbered options contract applies at final handoff or a genuine hard stop.

## Scope and authority

A started mutating batch is sufficient authorization for ordinary repository development and green-PR merge actions inside its declared objective. It is not reusable authority for production/provider side effects, secrets, destructive data/migrations, branch-protection weakening, billing, deployment/release, or other external actions whose canonical contract requires current explicit authority.

If one such authority is unavailable, continue every independent safe repository action first. Do not ask a broad confirmation question. Stop only when it is the sole remaining path, and then report the exact external authority/input required.

## Current audit snapshot — revalidate before use

Captured: **2026-09-27 UTC**

- Protected `main` observed during audit: `3d31fe8ac6f73605c7fbe9e321e7bd027b7fefb9`.
- Canonical phase/task on that main: `PHASE-09 / TASK-0049`.
- PR #414 is merged into the observed main.
- PR #415 is an older TASK-0049 carrier from the reused research branch and must be compared against the cleaner replacement before use.
- PR #416 is the clean TASK-0049 carrier observed as mergeable; its Continuity and Security workflows passed, while Application Foundation failed at PHP formatting. Under continuous mode that failure is a same-scope repair path, not a reason to ask the user what to do.
- `CURRENT-STATE.yaml` on observed main still references merged PR #414 and therefore must be treated as a compact stale index until live PR/main evidence is reconciled.
- Persistent status issue #102 also lagged the open-PR reality during this audit. Live GitHub evidence wins.

This snapshot is diagnostic only; it does not replace the mandatory resume reconciliation.

## Legacy staged parallel registry

`.ai/parallel/WORKSTREAMS.yaml` currently contains a staged historical TASK-0041 registry. It must not be treated as current PHASE-09 execution authority merely because open slots exist. New parallel onboarding must fail closed when the registry parent does not match the canonical active task.

<!-- WORKSTREAM_TABLE_START -->
| Merge group | Workstream | Module/capability | Slot | Assigned agent | Start status | Branch | PR merge strategy | Resume/sync strategy |
|---:|---|---|---|---|---|---|---|---|
| 10 | WS-0041-SUPERVISOR-CONTROL | Historical TASK-0041 Supervisor lane. | `occupied` | `supervisor-main` | `promotion_complete` | `control/task0041-shipping-acceleration` | squash | historical/staged |
| 20 | WS-0041-RETRY-CAPABILITY | Historical TASK-0041 lane. | **OPEN** | — | `shipping_merged_green` | `worker-1/task0041-retry-capability` | squash | historical/staged |
| 30 | WS-0041-APPROVAL-REVOCATION | Historical TASK-0041 lane. | **OPEN** | — | `shipping_merged_green` | `worker-2/task0041-approval-revocation` | squash | historical/staged |
| 40 | WS-0041-PROVIDER-DRIFT | Historical TASK-0041 lane. | **OPEN** | — | `shipping_merged_green` | `worker-3/task0041-provider-drift` | squash | historical/staged |
| 50 | WS-0041-OPERATOR-UX-CERT | Historical TASK-0041 lane. | **OPEN** | — | `shipping_merged_green` | `worker-4/task0041-operator-ux-cert` | squash | historical/staged |
<!-- WORKSTREAM_TABLE_END -->

## Final handoff

Only when the continuous batch ends, report the durable result, exact next safe action, phase/module progress, overall roadmap progress, and then expose the normal shuffled next-action options if further work remains.

A recorded technical blocker or a failed first repair attempt is not a handoff boundary. Continue through repair, fallback, or independent safe work while Workspace credit remains.
