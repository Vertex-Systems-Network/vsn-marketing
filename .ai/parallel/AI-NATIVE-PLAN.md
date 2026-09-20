# AI-Native Parallel Plan — TASK-0034 Safe Editor/Render/Compiler Pipeline

Status: **final acceptance**. TASK-0034 safe editor/render/compiler implementation is promoted to protected `main`; all worker lanes are completed and released, and Supervisor is the only active writer for final acceptance.

Supervisor: `supervisor-main`  
Control branch: `supervisor/TASK-0034`  
Parent task: `TASK-0034`  
Branch creation baseline: `59c79c28ea3a26a8960e393666f6c799050065fd`  
Active leases: `1`  
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
| 20 | WS-0034-RENDER-COMPILER | Deterministic pinned renderer/compiler contracts and artifact provenance | `open` | — | `completed` | `worker-2/TASK-0034` | squash | merged as PR #289; traversal remediation PR #294 |
| 30 | WS-0034-PREVIEW-REGRESSION | Isolated bounded preview/test orchestration and regression-result contracts | `open` | — | `completed` | `worker-3/TASK-0034` | squash | merged as PR #291 |
| 40 | WS-0034-CERTIFICATION | Adversarial/browser certification for sanitizer, render determinism, isolation and accessibility | `open` | — | `completed` | `worker-4/TASK-0034` | squash | merged as PR #296; preview-bound certification fix PR #297 |
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
- Wave 4 pre-certification audit found a concrete renderer configuration boundary gap: relative traversal strings such as `../../etc/passwd` were not rejected by `RenderCompilerPlanner` even though absolute paths, schemes and privileged keys were denied. Certification is parked until the renderer lane closes this explicit TASK-0034 path-traversal invariant.
- Control PR #293 parked certification and reopened only the renderer/compiler lane for bounded remediation.
- PR #294 closed relative POSIX/Windows plus encoded/double-encoded traversal handling and merged as `5bb0c0539d9a340fc67409df1e86f6ffe701af1b` after exact-head Shipping Fast Gate `35449184862` passed.
- Remediated ship head `5bb0c0539d9a340fc67409df1e86f6ffe701af1b` passed AI Continuity Guard `35449253920`, Shipping Fast Gate `35449253905`, and Application Foundation CI `35449253893`, including PostgreSQL/Redis integration, PHP 8.3, Playwright E2E, foundation, backend/architecture tests, static analysis, formatting, frontend tests and build.
- WS-0034-RENDER-COMPILER is completed and released again; Wave 4 certification may resume.
- PR #295 reactivated Wave 4 certification on the remediated ship baseline and merged as `ea8d5548861bfa5db4b60fdf79e8973db5a6a086`; that head passed AI Continuity Guard `35449589074`, Shipping Fast Gate `35449589102`, and Application Foundation CI `35449589038`.
- PR #296 merged the final adversarial/integration certification tests as `9554ee15d8a898fe3d130fa62fa1532ee0af21be` after corrected exact-head Shipping Fast Gate `35505699663` passed.
- Certification gating and the first post-merge PostgreSQL run proved the preview-vs-renderer resource bound by correctly failing closed where test fixtures used a preview default output limit above the pinned renderer limit; no product behavior defect was found.
- PR #297 aligned the remaining integration fixture to the exact pinned renderer output limit and merged as `4839f85cd8d453dd1f95cca3fc686bdbc5c2b0f5` after exact-head Shipping Fast Gate `35505872547` passed.
- Certified final ship head `4839f85cd8d453dd1f95cca3fc686bdbc5c2b0f5` passed AI Continuity Guard `35505933980`, Shipping Fast Gate `35505933967`, and Application Foundation CI `35505933975`, including PostgreSQL/Redis integration, PHP 8.3 compatibility, Playwright E2E, foundation, backend/architecture tests, static analysis, formatting, frontend tests and build.
- Merge group 40 is dependency-complete; the certification worker lease is released.
- PR #298 reconciled final ship certification and merged as `c79295b07c93daf7e164d8932b76f6d5bfc0e888`; that exact source head passed AI Continuity Guard `35506384437`, Application Foundation CI `35506384449`, and Security Supply Chain CI `35506384438` during protected-main promotion.
- PR #299 promoted the certified TASK-0034 implementation to protected `main` as `97038ee4f133c4231b61dbf9fcef2f08483e21ba`.
- Post-merge main head `97038ee4f133c4231b61dbf9fcef2f08483e21ba` passed AI Continuity Guard `35506533580`, Application Foundation CI `35506533578`, Security Supply Chain CI `35506533582`, Release Integrity `35506533591`, and OpenSSF Scorecard `35506533600`.
- AC-1 through AC-8 are reconciled true while TASK-0034 intentionally remains `ready` until this final acceptance PR passes its own exact-head gates.

## Exact next action

Run TASK-0034 final acceptance on a Supervisor-only control PR with AC-1 through AC-8 true while task status remains ready; merge only after exact-head AI Continuity Guard, Application Foundation CI and Security Supply Chain CI pass, then register and activate TASK-0035 in a separate guarded transition without pulling PHASE-07 publishing forward.
