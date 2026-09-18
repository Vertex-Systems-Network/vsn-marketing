# AI-Native Parallel Plan — TASK-0032 Canonical Content/Template Model

Status: **active — Wave 3 persistence/adversarial certification**. TASK-0032 Waves 1 and 2 are integrated and certified on `ship/week-1`. Shipping Mode now has two current writers: Supervisor plus the dependency-unlocked persistence/certification lane.

Supervisor: `supervisor-main`  
Control branch: `supervisor/TASK-0032`  
Shipping integration branch: `ship/week-1`  
Parent task: `TASK-0032`  
Branch creation baseline: `3bdc9e797cdded1073600dc7945381baa53a9e0f`  
Active writers: `2` (1 Supervisor + 1 persistence/certification worker)  
Shipping Mode writer cap: `5`  
Repository hard cap: `12`  
Merge strategy: `squash`  
Completion signal: `Work Done and Submitted`

## Frozen implementation invariants

- Canonical content, templates and reusable components are provider-neutral and workspace scoped; provider HTML/MIME/social payloads never become canonical source state.
- Stable identities are separate from immutable versions. Published, approved or execution-pinned versions are never rewritten; edits create explicit child versions with deterministic lineage.
- Canonical document/component trees are typed and schema-versioned. Unknown node/schema versions fail validation rather than being guessed.
- Template/component dependencies are explicit, version-aware, cycle-safe and impact-visible. Execution-pinned inputs cannot float to newer dependency versions.
- Variables, personalization slots and localization values are typed, deterministic and fail closed when missing or incompatible. They cannot execute code.
- Canonicalization/hash semantics are deterministic across equivalent input and are independent of provider render output.
- A render-input snapshot identifies exact source versions and future asset/brand references but does not run TASK-0033 asset transforms or TASK-0034 editor/compiler/render code.
- Workspace isolation, immutable-history enforcement, replay/idempotency, optimistic concurrency and dependency integrity must be proved on PostgreSQL before acceptance.
- No arbitrary authored JavaScript, live remote include/fetch, production rendering, provider publishing, PHASE-07 campaign scheduling or later-phase capability is introduced.
- Shared database migrations remain Supervisor-owned. The Wave 3 worker consumes the existing schema and must not write `database/migrations/**`.

<!-- WORKSTREAM_TABLE_START -->
| Merge group | Workstream | Module/capability | Slot | Assigned agent | Start status | Branch | PR merge strategy | Resume/sync strategy |
|---:|---|---|---|---|---|---|---|---|
| 5 | WS-0032-SUPERVISOR-CONTROL | TASK-0032 control + Supervisor-owned canonical persistence schema + merge coordination | `occupied` | `supervisor-main` | `active` | `supervisor/TASK-0032` | squash | merge latest ship/week-1 before resume |
| 10 | WS-0032-CONTENT-MODEL | Canonical content identities, immutable versions and typed provider-neutral document tree | `open` | — | `completed` | `worker-1/TASK-0032` | squash | merged as PR #255 |
| 20 | WS-0032-TEMPLATE-COMPONENTS | Template/component identities, immutable versions and explicit dependency references | `open` | — | `completed` | `worker-2/TASK-0032` | squash | merged as PR #257 |
| 30 | WS-0032-BINDINGS-CANONICALIZATION | Typed variables/localization, canonical hashes, dependency resolution and snapshot identity | `open` | — | `completed` | `worker-3/TASK-0032` | squash | merged as PR #259 |
| 40 | WS-0032-PERSISTENCE-CERTIFICATION | Database repositories plus PostgreSQL/adversarial isolation and immutability certification | `occupied` | `worker-task0032-persistence` | `active` | `worker-4/TASK-0032` | squash | merge latest ship/week-1 before implementation |
<!-- WORKSTREAM_TABLE_END -->

## Dependency-safe merge waves

1. **Wave 0 — control activation:** activate the bounded TASK-0032 registry on `ship/week-1`; no product implementation belongs in control-only PRs.
2. **Wave 1 — canonical contracts:** Supervisor schema plus Content and Templates/Component domain contracts integrate independently.
3. **Wave 2 — binding and canonicalization:** typed variables/localization, deterministic canonical JSON/hash, exact dependency resolution/impact visibility and render-input snapshot identity integrate after Wave 1.
4. **Wave 3 — persistence and adversarial certification:** implement repositories against the Supervisor-owned schema and prove workspace isolation, append-only/immutable versions, idempotent retries, conflict/optimistic-concurrency behavior, exact dependency integrity and deterministic hash persistence on real PostgreSQL paths.
5. **Wave 4 — integration/final acceptance:** `ship/week-1` must pass full Application Foundation CI and AI Continuity. Then promote only a green integration baseline to protected `main` and require full Continuity, Application and Security Supply Chain evidence before TASK-0032 acceptance or TASK-0033 activation.

## Integrated evidence

- PR #254 merged the Supervisor-owned canonical schema foundation as `da8a1844fc326a0396af14fa5070bc054c86acd3` after Shipping Fast Gate `35391424318` passed.
- PR #255 merged the canonical Content model as `bca543f960f45ef1a1adb62a151789539f15aa80` after exact-head Shipping Fast Gate `35392209129` passed.
- PR #257 merged the Templates/Component model as `471cf7155fb260b104fb938d2391298b5d16d02e` after exact-head Shipping Fast Gate `35392426901` passed.
- PR #259 merged deterministic bindings/canonicalization as `252c8c26a7cce1aba8ef652eefa317ed2c133c4f` after exact-head Shipping Fast Gate `35393056030` passed.
- The first Wave 2 integration push exposed PostgreSQL SQLSTATE 42830 because self-referential composite parent FKs were emitted before their referenced `(id, workspace_id)` unique keys. PR #260 reordered those constraints only and merged as `4dbdff7fe838f630afa1b9ee50e78dbae55deef4` after Shipping Fast Gate `35394168422` passed.
- PR #261 reconciled Supervisor global continuity state/checkpoint/journal and merged as `468cdab6aad65db7c6d7f6cc3a5390a897cca429` after exact-head Shipping Fast Gate `35394648440` passed.
- Certified integration head `468cdab6aad65db7c6d7f6cc3a5390a897cca429` passed AI Continuity Guard `35394761430`, Shipping Fast Gate `35394761441`, and Application Foundation CI `35394761466`, including PostgreSQL/Redis integration, E2E, PHP 8.3 floor, backend/architecture tests, static analysis, formatting, frontend tests and build.
- Merge group 30 is dependency-complete and the binding worker lease is released.

## Exact next action

Merge this Wave 3 control transition into `ship/week-1` after a green exact-head Shipping Fast Gate. Then fast-forward `worker-4/TASK-0032` to the resulting certified integration head and implement only the registered persistence repositories plus PostgreSQL/adversarial certification. Do not modify migrations, start TASK-0033 asset processing, or introduce TASK-0034 editor/compiler/render execution.
