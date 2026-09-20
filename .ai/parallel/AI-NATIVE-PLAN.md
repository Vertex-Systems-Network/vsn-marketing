# AI-Native Parallel Plan — TASK-0035 Brand Knowledge/Kit and Provider Template Synchronization

Status: **staged — task transition only**. TASK-0035 is the active PHASE-06 task after guarded transition, but product work remains unleased until a separate bounded activation passes its own gates.

Supervisor: `supervisor-main`  
Control branch: `supervisor/TASK-0035`  
Parent task: `TASK-0035`  
Branch creation baseline: `0f282d732a79c5838f2424fb3b2641245060b8ad`  
Active leases: `0`  
Shipping Mode writer cap: `5`  
Repository hard cap: `12`  
Merge strategy: `squash`  
Completion signal: `Work Done and Submitted`

## Frozen implementation invariants

- Brand knowledge/style tokens are workspace-scoped, versioned canonical references with immutable published/execution-pinned versions and explicit provenance.
- Canonical templates/components consume exact brand versions deterministically; brand defaults never silently float underneath pinned render inputs.
- Reusable components expose explicit local/global scope, dependency impact and immutable version transitions.
- Shared/global component approval/readiness belongs to PHASE-06 content governance and must not pull PHASE-07 campaign approval, scheduling or publishing forward.
- Provider-template records are mappings/derivatives/reconciled copies; provider-owned templates never become authoritative VSN canonical content.
- Provider/channel template and media requirements remain versioned/effective-dated capability evidence rather than global canonical constants.
- Provider-template synchronization is replay/idempotency safe, detects drift deterministically and cannot silently overwrite canonical VSN versions or approved reusable components.
- Provider credentials, upload identifiers and live publication lifecycle stay outside canonical brand/content/template state and out of browser/editor surfaces.
- TASK-0035 does not implement provider publishing or PHASE-07 campaign execution.
- Final certification must prove workspace isolation, deterministic resolution, approval integrity, drift/reconciliation, replay safety and provider-neutral canonical authority before TASK-0036.

<!-- WORKSTREAM_TABLE_START -->
| Merge group | Workstream | Module/capability | Slot | Assigned agent | Start status | Branch | PR merge strategy | Resume/sync strategy |
|---:|---|---|---|---|---|---|---|---|
| 5 | WS-0035-SUPERVISOR-CONTROL | TASK-0035 staged activation, shared contract coordination and final acceptance | `occupied` | `supervisor-main` | `assigned_waiting_for_task_activation` | `supervisor/TASK-0035` | squash | merge latest main before resume |
| 10 | WS-0035-BRAND-KIT | Versioned workspace brand knowledge/kit and deterministic token/reference resolution | **OPEN** | — | `staged` | `worker-1/TASK-0035` | squash | merge latest main before resume |
| 20 | WS-0035-REUSABLE-APPROVAL | Reusable component scope/dependency impact plus PHASE-06 approval/readiness governance | **OPEN** | — | `staged` | `worker-2/TASK-0035` | squash | merge latest main before resume |
| 30 | WS-0035-PROVIDER-TEMPLATE-SYNC | Provider-template mappings, derivative identity, drift/reconciliation and replay-safe sync | **OPEN** | — | `staged` | `worker-3/TASK-0035` | squash | merge latest main before resume |
| 40 | WS-0035-CERTIFICATION | Adversarial/integration certification for brand/component/provider-template synchronization | **OPEN** | — | `staged` | `worker-4/TASK-0035` | squash | merge latest main before resume |
<!-- WORKSTREAM_TABLE_END -->

## Dependency-safe waves

1. **Transition/staging:** complete TASK-0034, activate TASK-0035 canonically, pre-create bounded branches and keep zero product leases.
2. **Wave 1:** versioned workspace brand knowledge/kit contracts and deterministic brand reference resolution.
3. **Wave 2:** reusable component local/global scope, dependency impact and PHASE-06 approval/readiness semantics after brand contracts stabilize.
4. **Wave 3:** provider-template mapping/synchronization/reconciliation after canonical brand and reusable governance contracts integrate.
5. **Wave 4:** adversarial/integration/security certification and final exact-head acceptance before TASK-0036.

## Trusted transition evidence

- TASK-0034 final acceptance PR #300 merged on protected `main` as `d967a3ccd3f6acf27b5c2b959bfd2f93629398f3`; that head passed AI Continuity Guard `35507577279`, Application Foundation CI `35507577233`, Security Supply Chain CI `35507577248`, Release Integrity `35507577264`, and OpenSSF Scorecard `35507577241`.
- TASK-0035 registration PR #301 merged on protected `main` as `0f282d732a79c5838f2424fb3b2641245060b8ad`; that head passed AI Continuity Guard `35507944337`, Application Foundation CI `35507944240`, Security Supply Chain CI `35507944265`, Release Integrity `35507944211`, and OpenSSF Scorecard `35507944309`.
- Supervisor plus four worker branches were pre-created from the trusted TASK-0035 registration main head. No TASK-0035 product lease is active.

## Exact next action

After guarded activation, map the frozen TASK-0031 brand/reusable-component/provider-template research onto the canonical content, asset and renderer contracts; implement versioned brand knowledge/kit references, reusable approved components and provider-template synchronization/reconciliation while keeping VSN canonical versions authoritative and leaving PHASE-07 campaign publishing, scheduling and execution out of scope.
