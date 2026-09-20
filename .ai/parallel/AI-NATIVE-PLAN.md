# AI-Native Parallel Plan — TASK-0035 Brand Knowledge/Kit and Provider Template Synchronization

Status: **active — Wave 3 provider-template synchronization and reconciliation**. TASK-0035 is the active PHASE-06 task. Waves 1 and 2 are integrated and certified on `ship/week-1`; Supervisor plus the dependency-unlocked provider-template synchronization lane are active while final certification remains staged.

Supervisor: `supervisor-main`  
Control branch: `supervisor/TASK-0035`  
Parent task: `TASK-0035`  
Branch creation baseline: `760be505c8ae1893f18209b580b859f87d7ac7e0`  
Active leases: `2`  
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
| 5 | WS-0035-SUPERVISOR-CONTROL | TASK-0035 staged activation, shared contract coordination and final acceptance | `occupied` | `supervisor-main` | `active` | `supervisor/TASK-0035` | squash | merge latest ship/week-1 before resume |
| 10 | WS-0035-BRAND-KIT | Versioned workspace brand knowledge/kit and deterministic token/reference resolution | `open` | — | `completed` | `worker-1/TASK-0035` | squash | merged as PR #304; PHP 8.3 fixture fix PR #305 |
| 20 | WS-0035-REUSABLE-APPROVAL | Reusable component scope/dependency impact plus PHASE-06 approval/readiness governance | `open` | — | `completed` | `worker-2/TASK-0035` | squash | merged as PR #307 |
| 30 | WS-0035-PROVIDER-TEMPLATE-SYNC | Provider-template mappings, derivative identity, drift/reconciliation and replay-safe sync | `occupied` | `worker-task0035-provider-sync` | `active` | `worker-3/TASK-0035` | squash | merge latest ship/week-1 before resume |
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
- TASK-0034 → TASK-0035 transition PR #302 merged on protected `main` as `760be505c8ae1893f18209b580b859f87d7ac7e0`; post-merge AI Continuity Guard `35509003689`, Application Foundation CI `35509003709`, Security Supply Chain CI `35509003690`, Release Integrity `35509003659`, and OpenSSF Scorecard `35509003650` all passed.
- `ship/week-1` was tree-audited against protected main after TASK-0034 promotion; product trees were identical and only superseded AI control/state files differed, so the shipping baseline was safely realigned to `760be505c8ae1893f18209b580b859f87d7ac7e0` before Wave 1 activation.
- PR #303 activated TASK-0035 Wave 1 and merged as `e51b17a8e4200ae385ce113def384ce07e026fbb`; that exact head passed AI Continuity Guard `35509262657`, Shipping Fast Gate `35509262663`, and Application Foundation CI `35509262655`.
- PR #304 merged versioned Brand Kit contracts as `416c9bf1ec3d31df9f492c2ee01df190392c79d3` after exact-head Shipping Fast Gate `35509682006` passed.
- Post-merge PHP 8.3 certification correctly exposed a test-only temporary-object parse incompatibility. PR #305 corrected only the Brand Kit test syntax and merged as `bc3ba0041084b9ac079990fa44edff69fe12a893`.
- Final Wave 1 ship head `bc3ba0041084b9ac079990fa44edff69fe12a893` passed AI Continuity Guard `35509934838`, Shipping Fast Gate `35509934796`, and Application Foundation CI `35509934799`, including PostgreSQL/Redis integration, PHP 8.3 compatibility, Playwright E2E, foundation, backend/architecture tests, static analysis, formatting, frontend tests and build.
- WS-0035-BRAND-KIT is completed and released.
- PR #307 merged reusable component governance as `2795791b00d8b0cb9ea7a91152d7af9bbc73007b` after exact-head Shipping Fast Gate `35510872075` passed.
- Wave 2 ship head `2795791b00d8b0cb9ea7a91152d7af9bbc73007b` passed AI Continuity Guard `35510933087`, Shipping Fast Gate `35510933084`, and Application Foundation CI `35510933098`, including PostgreSQL/Redis integration, PHP 8.3 compatibility, Playwright E2E, foundation, backend/architecture tests, static analysis, formatting, frontend tests and build.
- WS-0035-REUSABLE-APPROVAL is completed and released.

## Exact next action

Merge this Wave 3 activation into `ship/week-1` only after exact-head Shipping Fast Gate passes. Then fast-forward `supervisor/TASK-0035` and `worker-3/TASK-0035` to the resulting certified integration head and implement only workspace-scoped provider-template mappings, deterministic canonical-to-provider derivative identities, version/effective-date drift detection, replay/idempotency-safe reconciliation and versioned capability-evidence validation/fallbacks. Keep VSN canonical content/brand/component versions authoritative; provider credentials, upload identifiers, live remote fetch, publication lifecycle, campaign scheduling and PHASE-07 execution remain out of scope.
