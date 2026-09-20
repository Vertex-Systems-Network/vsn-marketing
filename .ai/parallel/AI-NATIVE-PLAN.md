# AI-Native Parallel Plan — TASK-0035 Brand Knowledge/Kit and Provider Template Synchronization

Status: **final acceptance**. TASK-0035 brand knowledge/kit, reusable-component governance and provider-template synchronization/reconciliation are promoted to protected `main`; all worker lanes are completed and released, and Supervisor is the only active writer for final acceptance.

Supervisor: `supervisor-main`  
Control branch: `supervisor/TASK-0035`  
Parent task: `TASK-0035`  
Branch creation baseline: `760be505c8ae1893f18209b580b859f87d7ac7e0`  
Active leases: `1`  
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
| 5 | WS-0035-SUPERVISOR-CONTROL | TASK-0035 staged activation, shared contract coordination and final acceptance | `occupied` | `supervisor-main` | `active` | `supervisor/TASK-0035` | squash | merge latest main before resume |
| 10 | WS-0035-BRAND-KIT | Versioned workspace brand knowledge/kit and deterministic token/reference resolution | `open` | — | `completed` | `worker-1/TASK-0035` | squash | merged as PR #304; PHP 8.3 fixture fix PR #305 |
| 20 | WS-0035-REUSABLE-APPROVAL | Reusable component scope/dependency impact plus PHASE-06 approval/readiness governance | `open` | — | `completed` | `worker-2/TASK-0035` | squash | merged as PR #307 |
| 30 | WS-0035-PROVIDER-TEMPLATE-SYNC | Provider-template mappings, derivative identity, drift/reconciliation and replay-safe sync | `open` | — | `completed` | `worker-3/TASK-0035` | squash | merged as PR #309 |
| 40 | WS-0035-CERTIFICATION | Adversarial/integration certification for brand/component/provider-template synchronization | `open` | — | `completed` | `worker-4/TASK-0035` | squash | merged as PR #311 |
<!-- WORKSTREAM_TABLE_END -->

## Dependency-safe waves

1. **Transition/staging:** complete TASK-0034, activate TASK-0035 canonically, pre-create bounded branches and keep zero product leases.
2. **Wave 1:** versioned workspace brand knowledge/kit contracts and deterministic brand reference resolution.
3. **Wave 2:** reusable component local/global scope, dependency impact and PHASE-06 approval/readiness semantics after brand contracts stabilize.
4. **Wave 3:** provider-template mapping/synchronization/reconciliation after canonical brand and reusable governance contracts integrate.
5. **Wave 4:** adversarial/integration/security certification and final exact-head acceptance before TASK-0036.

## Integrated evidence

- TASK-0034 final acceptance PR #300 merged on protected `main` as `d967a3ccd3f6acf27b5c2b959bfd2f93629398f3`; that head passed AI Continuity Guard `35507577279`, Application Foundation CI `35507577233`, Security Supply Chain CI `35507577248`, Release Integrity `35507577264`, and OpenSSF Scorecard `35507577241`.
- TASK-0035 registration PR #301 merged on protected `main` as `0f282d732a79c5838f2424fb3b2641245060b8ad`; that head passed AI Continuity Guard `35507944337`, Application Foundation CI `35507944240`, Security Supply Chain CI `35507944265`, Release Integrity `35507944211`, and OpenSSF Scorecard `35507944309`.
- TASK-0034 → TASK-0035 transition PR #302 merged on protected `main` as `760be505c8ae1893f18209b580b859f87d7ac7e0`; post-merge AI Continuity Guard `35509003689`, Application Foundation CI `35509003709`, Security Supply Chain CI `35509003690`, Release Integrity `35509003659`, and OpenSSF Scorecard `35509003650` all passed.
- PR #304 merged versioned Brand Kit contracts as `416c9bf1ec3d31df9f492c2ee01df190392c79d3`; PR #305 restored PHP 8.3 test syntax compatibility.
- PR #307 merged reusable component governance as `2795791b00d8b0cb9ea7a91152d7af9bbc73007b`.
- PR #309 merged provider-template synchronization/reconciliation contracts as `1c72aab370fa5adf1c5380eea33d222a872841c3`.
- PR #311 merged final TASK-0035 integration/security certification tests as `a54c8cb318ad70ca102ae5c7aab654aca85f919f`.
- PR #312 reconciled final ship certification and merged as `affad05aa50c3ca105cc0a3cd8940a37079cd0a8`; that exact source head passed AI Continuity Guard `35512463028`, Application Foundation CI `35512463013`, and Security Supply Chain CI `35512463052` during protected-main promotion.
- PR #313 promoted the certified TASK-0035 implementation to protected `main` as `d956a416d90d78387e96f8164c6134c596d45bac`.
- Post-merge main head `d956a416d90d78387e96f8164c6134c596d45bac` passed AI Continuity Guard `35512567839`, Application Foundation CI `35512567860`, Security Supply Chain CI `35512567875`, Release Integrity `35512567831`, and OpenSSF Scorecard `35512567834`.
- AC-1 through AC-8 are reconciled true while TASK-0035 intentionally remains `ready` until this final acceptance PR passes its own exact-head gates.

## Exact next action

Run TASK-0035 final acceptance on a Supervisor-only control PR with AC-1 through AC-8 true while task status remains ready; merge only after exact-head AI Continuity Guard, Application Foundation CI and Security Supply Chain CI pass, then register and activate TASK-0036 in a separate guarded transition without pulling PHASE-07 campaign publishing, scheduling or execution forward.
