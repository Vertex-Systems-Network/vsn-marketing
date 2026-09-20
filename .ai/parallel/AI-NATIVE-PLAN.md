# AI-Native Parallel Plan — TASK-0036 PHASE-06 Certification

Status: **active — PHASE-06 certification wave**. TASK-0031 through TASK-0035 are completed on protected `main`; TASK-0036 is the only active PHASE-06 task. Supervisor plus one focused certification worker are active on the certified `ship/week-1` baseline.

Supervisor: `supervisor-main`  
Control branch: `supervisor/TASK-0036`  
Parent task: `TASK-0036`  
Branch creation baseline: `36120709c4d8e63160871894ea61fca67a386934`  
Active leases: `2`  
Shipping Mode writer cap: `5`  
Repository hard cap: `12`  
Merge strategy: `squash`  
Completion signal: `Work Done and Submitted`

## Frozen certification invariants

- TASK-0031 through TASK-0035 accepted evidence remains authoritative and may not be weakened merely to make phase certification pass.
- Canonical content, template, component, brand, asset, render and dependency identities remain exact-version pinned, immutable where published/execution-pinned, deterministic and provider-neutral.
- Workspace isolation is fail-closed across content, templates, reusable components, brand versions, assets, render/preview inputs and provider-template mappings/observations.
- Authored markup remains untrusted; script/event execution, traversal, unrestricted remote/network access, ambient secrets/credentials, renderer-resource escape and canonical mutation remain forbidden.
- Asset originals remain immutable with explicit rights/provenance and deterministic derived-variant lineage; destructive overwrite and untracked derivatives remain forbidden.
- Accessibility and representative responsive visual/client regression findings remain tied to exact source/render identities and bounded deterministic targets.
- Provider template/media requirements remain versioned/effective-dated capability evidence; external provider drift never becomes authoritative canonical VSN source.
- TASK-0036 is certification-only. It introduces no product behavior, provider publication, campaign approval, scheduling, publishing or other PHASE-07 execution capability.

<!-- WORKSTREAM_TABLE_START -->
| Merge group | Workstream | Module/capability | Slot | Assigned agent | Start status | Branch | PR merge strategy | Resume/sync strategy |
|---:|---|---|---|---|---|---|---|---|
| 10 | WS-0036-SUPERVISOR-CONTROL | Own PHASE-06 certification integration, shared-path coordination, shipping-baseline realignment, exact-head acceptance and terminal phase closeout without weakening TASK-0031 through TASK-0035 canonical, asset, rendering, brand or provider-neutral authority and without pulling PHASE-07 forward. | `occupied` | `supervisor-main` | `active` | `supervisor/TASK-0036` | squash | merge latest ship/week-1 before resume |
| 20 | WS-0036-PHASE-CERTIFICATION | Certify PHASE-06 exact-version reproducibility, workspace isolation, asset provenance and variant safety, rendering and preview security, accessibility and representative visual/client regression, and provider-template synchronization/drift boundaries without adding product behavior. | `occupied` | `worker-task0036-certification` | `active` | `worker-1/TASK-0036` | squash | merge latest ship/week-1 before resume |
<!-- WORKSTREAM_TABLE_END -->

## Certified activation baseline

- TASK-0036 transition PR #316 merged on protected `main` as `36120709c4d8e63160871894ea61fca67a386934` after exact-head AI Continuity Guard `35541256162`, Application Foundation CI `35541256268`, and Security Supply Chain CI `35541256237` passed.
- Transition main head `36120709c4d8e63160871894ea61fca67a386934` passed AI Continuity Guard `35541384517`, Application Foundation CI `35541384419`, Security Supply Chain CI `35541384540`, Release Integrity `35541384552`, and OpenSSF Scorecard `35541384507`.
- A recursive Git-tree audit between prior certified ship head `affad05aa50c3ca105cc0a3cd8940a37079cd0a8` and transition main `36120709c4d8e63160871894ea61fca67a386934` found zero non-`.ai/**` differences; only nine canonical AI control/state files differed.
- `ship/week-1` was therefore safely realigned to `36120709c4d8e63160871894ea61fca67a386934`. The historical certified ship head is preserved at `archive/task-0035-final-ship` so append-only push verification retains an addressable base.
- Exact ship head `36120709c4d8e63160871894ea61fca67a386934` passed AI Continuity Guard `35541535176` after the historical-base reachability repair, Shipping Fast Gate `35541535218`, and Application Foundation CI `35541535198`, including PostgreSQL/Redis integration, PHP floor, Playwright E2E, foundation, static analysis, formatting, frontend tests and build.

## Exact next action

Merge this activation into `ship/week-1` only after the activation PR exact head passes Shipping Fast Gate. Then fast-forward `worker-1/TASK-0036` to the resulting certified ship head and add only the two leased certification test files. Reuse accepted TASK-0032 through TASK-0035 contracts; do not add product behavior merely to satisfy certification, and do not activate PHASE-07.
