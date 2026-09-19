# AI-Native Parallel Plan — TASK-0034 Safe Editor/Render/Compiler Pipeline

Status: **active — Wave 2 deterministic renderer/compiler contracts**. TASK-0034 is the active PHASE-06 task. Wave 1 authoring/sanitizer contracts are integrated and certified on `ship/week-1`; Supervisor plus the dependency-unlocked renderer/compiler lane are active while preview/regression and certification remain staged.

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
| 20 | WS-0034-RENDER-COMPILER | Deterministic pinned renderer/compiler contracts and artifact provenance | `occupied` | `worker-task0034-renderer` | `active` | `worker-2/TASK-0034` | squash | merge latest ship/week-1 before resume |
| 30 | WS-0034-PREVIEW-REGRESSION | Isolated bounded preview/test orchestration and regression-result contracts | **OPEN** | — | `staged` | `worker-3/TASK-0034` | squash | merge latest main before resume |
| 40 | WS-0034-CERTIFICATION | Adversarial/browser certification for sanitizer, render determinism, isolation and accessibility | **OPEN** | — | `staged` | `worker-4/TASK-0034` | squash | merge latest main before resume |
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

## Exact next action

Merge this Wave 2 control activation into `ship/week-1` only after exact-head Shipping Fast Gate passes. Then fast-forward `worker-2/TASK-0034` to the certified integration head and implement only deterministic renderer/compiler contracts and artifact provenance pinned to exact canonical content/template/component/asset/variable/localization inputs plus renderer identity/version/configuration. Do not implement preview privilege, ambient secret/network/filesystem access, provider publishing, TASK-0035 brand/provider-template synchronization or PHASE-07 behavior.
