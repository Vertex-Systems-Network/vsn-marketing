# AI-Native Parallel Plan — TASK-0032 Canonical Content/Template Model

Status: **final acceptance**. TASK-0032 canonical content/template/version/component implementation is promoted to protected `main`. All worker lanes are completed and released; Supervisor is the only active writer for final acceptance and the later guarded TASK-0033 handoff.

Supervisor: `supervisor-main`  
Control branch: `supervisor/TASK-0032`  
Shipping integration branch: `ship/week-1`  
Parent task: `TASK-0032`  
Branch creation baseline: `1bfaeabd70325e7fea2c8acaaf5dea4860a095ae`  
Active writers: `1` (Supervisor only)  
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
| 5 | WS-0032-SUPERVISOR-CONTROL | TASK-0032 final acceptance + continuity/task-state reconciliation + guarded successor handoff | `occupied` | `supervisor-main` | `active` | `supervisor/TASK-0032` | squash | use trusted protected-main baseline |
| 10 | WS-0032-CONTENT-MODEL | Canonical content identities, immutable versions and typed provider-neutral document tree | `open` | — | `completed` | `worker-1/TASK-0032` | squash | merged as PR #255 |
| 20 | WS-0032-TEMPLATE-COMPONENTS | Template/component identities, immutable versions and explicit dependency references | `open` | — | `completed` | `worker-2/TASK-0032` | squash | merged as PR #257 |
| 30 | WS-0032-BINDINGS-CANONICALIZATION | Typed variables/localization, canonical hashes, dependency resolution and snapshot identity | `open` | — | `completed` | `worker-3/TASK-0032` | squash | merged as PR #259 |
| 40 | WS-0032-PERSISTENCE-CERTIFICATION | Database repositories plus PostgreSQL/adversarial isolation and immutability certification | `open` | — | `completed` | `worker-4/TASK-0032` | squash | merged as PR #263; replay fix PR #264 |
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
- PR #263 merged TASK-0032 persistence repositories and PostgreSQL/adversarial certification as `0d245a5fd14f85bb1005eed5133277429a241596` after exact-head Shipping Fast Gate `35397233418` passed.
- The first Wave 3 integration run exposed an exact-replay ordering regression: global version-ID conflict validation ran before the workspace-scoped idempotency replay path. PR #264 corrected only that ordering and merged as `29692360c4a425864fd638129e95ed6b13b46c7c` after exact-head Shipping Fast Gate `35397548286` passed.
- Certified final ship head `29692360c4a425864fd638129e95ed6b13b46c7c` passed AI Continuity Guard `35397658336`, Shipping Fast Gate `35397658293`, and Application Foundation CI `35397658347`, including PostgreSQL/Redis integration, E2E, PHP 8.3 floor, foundation, backend/architecture tests, static analysis, formatting, frontend tests and build.
- Merge group 40 is dependency-complete; the persistence worker lease is released.
- PR #266 promoted the certified Shipping Mode baseline to protected `main` as `1bfaeabd70325e7fea2c8acaaf5dea4860a095ae`; its exact source head `094e91793ab4a977412180edd28c572ce6c04bba` passed AI Continuity Guard `35398429953`, Application Foundation CI `35398429929`, and Security Supply Chain CI `35398429945`.
- Post-merge protected-main head `1bfaeabd70325e7fea2c8acaaf5dea4860a095ae` passed AI Continuity Guard `35398634375`, Application Foundation CI `35398634427`, Security Supply Chain CI `35398634220`, Release Integrity `35398634276`, and OpenSSF Scorecard `35398634293`.
- AC-1 through AC-8 are reconciled true while TASK-0032 intentionally remains `ready` until this Supervisor-only final acceptance PR passes its own exact-head acceptance gates and merges.

## Exact next action

Run TASK-0032 final acceptance on a Supervisor-only control PR with AC-1 through AC-8 true while task status remains ready; merge only after exact-head AI Continuity Guard, Application Foundation CI and Security Supply Chain CI pass, then complete TASK-0032 and explicitly register/activate TASK-0033 in a separate guarded transition. Do not start TASK-0033 asset processing before that transition.
