# AI-Native Parallel Plan — TASK-0034 Safe Editor/Render/Compiler Pipeline

Status: **active — Wave 4 final adversarial/security certification**. TASK-0034 is the active PHASE-06 task. Waves 1 through 3 are integrated and certified on `ship/week-1`; Supervisor plus the final certification lane are active.

Supervisor: `supervisor-main`  
Control branch: `supervisor/TASK-0034`  
Parent task: `TASK-0034`  
Branch creation baseline: `59c79c28ea3a26a8960e393666f6c799050065fd`  
Active leases: `2`  
Shipping Mode writer cap: `5`  
Repository hard cap: `12`  
Merge strategy: `squash`  
Completion signal: `Work Done and Submitted`

## Frozen implementation invariants

- Visual editing, bounded safe-code/import pathways and future AI-assisted authoring converge on the same canonical content/template/component version model.
- Published or execution-pinned source versions remain immutable; authoring changes create explicit new canonical versions.
- User-authored HTML/CSS is untrusted. Script, event handlers, unsafe URLs, unsupported markup and unsafe post-sanitization mutation fail closed.
- Renderer/compiler artifacts are deterministic derivatives of exact pinned content/template/component/asset/variable/localization inputs plus renderer identity, version and configuration.
- Renderer/compiler execution is bounded and replay/idempotency safe with no arbitrary shell/code execution, ambient secrets, unrestricted filesystem access, path traversal, live remote include/fetch or uncontrolled network access.
- Preview/test execution is isolated from privileged credentials and production publishing, cannot mutate canonical state and produces explicitly non-authoritative derivative artifacts.
- Provider/channel requirements remain versioned/effective-dated capability evidence; provider credentials, upload identifiers and publishing lifecycle never become canonical editor/render state.
- Accessibility semantics and deterministic validation findings are attached to exact source/render identities.
- TASK-0034 does not implement TASK-0035 brand/provider-template synchronization or PHASE-07 publishing.

<!-- WORKSTREAM_TABLE_START -->
| Merge group | Workstream | Module/capability | Slot | Assigned agent | Start status | Branch | PR merge strategy | Resume/sync strategy |
|---:|---|---|---|---|---|---|---|---|
| 5 | WS-0034-SUPERVISOR-CONTROL | TASK-0034 staged activation, shared contract coordination and final acceptance | `occupied` | `supervisor-main` | `active` | `supervisor/TASK-0034` | squash | merge latest ship/week-1 before resume |
| 10 | WS-0034-AUTHORING-SANITIZER | Canonical visual/safe-code authoring boundaries plus target-aware sanitization | `open` | — | `completed` | `worker-1/TASK-0034` | squash | merged as PR #287 |
| 20 | WS-0034-RENDER-COMPILER | Deterministic pinned renderer/compiler contracts and artifact provenance | `open` | — | `completed` | `worker-2/TASK-0034` | squash | merged as PR #289 |
| 30 | WS-0034-PREVIEW-REGRESSION | Isolated bounded preview/test orchestration and regression-result contracts | `open` | — | `completed` | `worker-3/TASK-0034` | squash | merged as PR #291 |
| 40 | WS-0034-CERTIFICATION | Adversarial/browser certification for sanitizer, render determinism, isolation and accessibility | `occupied` | `worker-task0034-certification` | `active` | `worker-4/TASK-0034` | squash | merge latest ship/week-1 before resume |
<!-- WORKSTREAM_TABLE_END -->

## Dependency-safe waves

1. **Transition/staging:** complete TASK-0033, activate TASK-0034 canonically, pre-create branches and keep zero leases.
2. **Wave 1:** authoring/sanitizer contracts and any Supervisor-owned shared persistence/control contract approved during bounded activation.
3. **Wave 2:** deterministic renderer/compiler artifact contracts after sanitized canonical authoring inputs are stable.
4. **Wave 3:** isolated preview/test orchestration plus representative regression infrastructure after renderer contracts integrate.
5. **Wave 4:** adversarial/browser/security certification and final exact-head acceptance before TASK-0035.

## Integrated evidence

- PR #286 activated TASK-0034 Wave 1 on certified ship baseline and merged as `1bb2d019fc8b509aa3f5e034a6f62f872871e5ba`.
- PR #287 merged canonical authoring and fail-closed sanitizer contracts as `b3daf9b763625cbc699b7ad345184b02d61e4fb0`.
- Ship head `b3daf9b763625cbc699b7ad345184b02d61e4fb0` passed AI Continuity Guard `35431693131`, Shipping Fast Gate `35431693129`, and Application Foundation CI `35431693198`, including PostgreSQL/Redis integration, PHP 8.3, Playwright E2E, foundation, backend/architecture tests, static analysis, formatting, frontend tests and build.
- WS-0034-AUTHORING-SANITIZER is completed and released.
- PR #288 activated TASK-0034 Wave 2 renderer/compiler work and merged as `1524edb3ac44ce2f46da3d8779f8bcfa72699990`.
- PR #289 merged deterministic renderer/compiler contracts and artifact provenance as `df214a96a62990df5ee54cb3631cc9e1af9b5d0b` after exact-head Shipping Fast Gate `35432097812` passed.
- Ship head `df214a96a62990df5ee54cb3631cc9e1af9b5d0b` passed AI Continuity Guard `35436326456`, Shipping Fast Gate `35436326437`, and Application Foundation CI `35436326451`, including PostgreSQL/Redis integration, PHP 8.3, Playwright E2E, foundation, backend/architecture tests, static analysis, formatting, frontend tests and build.
- WS-0034-RENDER-COMPILER is completed and released.
- PR #290 activated TASK-0034 Wave 3 preview/regression work and merged as `93226d56ee4f1cc98bc272c8ba5ca0fb5af3ee87`.
- PR #291 merged isolated deterministic preview/regression contracts as `5ddff4271f63edc1ccd1aacdf6debe2fc4b1a35b` after exact-head Shipping Fast Gate `35436808787` passed.
- Ship head `5ddff4271f63edc1ccd1aacdf6debe2fc4b1a35b` passed AI Continuity Guard `35436893625`, Shipping Fast Gate `35436893600`, and Application Foundation CI `35436893614`, including PostgreSQL/Redis integration, PHP 8.3, Playwright E2E, foundation, backend/architecture tests, static analysis, formatting, frontend tests and build.
- WS-0034-PREVIEW-REGRESSION is completed and released.

## Exact next action

Merge this Wave 4 control activation into `ship/week-1` only after exact-head Shipping Fast Gate passes. Then fast-forward `worker-4/TASK-0034` to the resulting certified integration head and add only adversarial/integration certification tests that prove sanitizer bypass resistance, deterministic renderer and preview identities, isolation/resource bounds, workspace-safe pinned inputs, accessibility findings, replay/idempotency and absence of provider publishing or privileged execution. Do not add product behavior merely to satisfy certification, and do not activate TASK-0035 before final protected-main acceptance.
