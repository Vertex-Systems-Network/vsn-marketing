# AI-Native Parallel Plan — TASK-0036 PHASE-06 Certification

Status: **staged — TASK-0036 phase-wide certification**. TASK-0035 is completed on protected `main`; TASK-0036 is the active PHASE-06 certification task. Supervisor and one focused certification worker branch are pre-created, but this transition holds zero active leases until the trusted transition baseline is accepted and the shipping integration branch is safely realigned.

Supervisor: `supervisor-main`  
Control branch: `supervisor/TASK-0036`  
Parent task: `TASK-0036`  
Branch creation baseline: `b1d73ccf16aa8e2977738d8a4dca89441d455c78`  
Active leases: `0`  
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
- TASK-0036 is certification-only. It introduces no provider publication, campaign approval, scheduling, publishing or other PHASE-07 execution capability.

<!-- WORKSTREAM_TABLE_START -->
| Merge group | Workstream | Module/capability | Slot | Assigned agent | Start status | Branch | PR merge strategy | Resume/sync strategy |
|---:|---|---|---|---|---|---|---|---|
| 10 | WS-0036-SUPERVISOR-CONTROL | Own PHASE-06 certification integration, shared-path coordination, shipping-baseline realignment, exact-head acceptance and terminal phase closeout without weakening TASK-0031 through TASK-0035 canonical, asset, rendering, brand or provider-neutral authority and without pulling PHASE-07 forward. | `occupied` | `supervisor-main` | `staged` | `supervisor/TASK-0036` | squash | merge latest main before resume |
| 20 | WS-0036-PHASE-CERTIFICATION | Certify PHASE-06 exact-version reproducibility, workspace isolation, asset provenance and variant safety, rendering and preview security, accessibility and representative visual/client regression, and provider-template synchronization/drift boundaries without adding product behavior. | **OPEN** | — | `staged` | `worker-1/TASK-0036` | squash | merge latest main before resume |
<!-- WORKSTREAM_TABLE_END -->

## Trusted transition evidence

- TASK-0035 final acceptance PR #314 merged on protected `main` as `00821175143b6c41e66e2544b968ef8098524773`; that head passed AI Continuity Guard `35512912213`, Application Foundation CI `35512912226`, Security Supply Chain CI `35512912282`, Release Integrity `35512912277`, and OpenSSF Scorecard `35512912322`.
- TASK-0036 registration PR #315 merged on protected `main` as `b1d73ccf16aa8e2977738d8a4dca89441d455c78` after exact-head AI Continuity Guard `35540787874`, Application Foundation CI `35540787783`, and Security Supply Chain CI `35540787767` passed.
- Registration main head `b1d73ccf16aa8e2977738d8a4dca89441d455c78` passed AI Continuity Guard `35540932113`, Application Foundation CI `35540932125`, Security Supply Chain CI `35540932115`, Release Integrity `35540932147`, and OpenSSF Scorecard `35540932123`.
- `supervisor/TASK-0036` and `worker-1/TASK-0036` were pre-created from the trusted registration baseline before these plan writes.
- This transition stages exactly one certification worker and keeps active leases at zero.

## Exact next action

After this TASK-0035 to TASK-0036 transition merges and its post-merge protected-main gates pass, audit and realign `ship/week-1` to the trusted TASK-0036 baseline, then activate only the staged PHASE-06 certification worker with test-only leases. Do not add product behavior or activate PHASE-07 campaign approval, scheduling, publishing or execution.
