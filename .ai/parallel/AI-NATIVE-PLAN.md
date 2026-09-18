# AI-Native Parallel Plan — TASK-0032 Canonical Content/Template Model

Status: **active — Wave 2 binding/canonicalization**. TASK-0032 Wave 1 schema, Content and Templates/Component contracts are integrated on `ship/week-1`. Shipping Mode now has two current writers: Supervisor plus the dependency-unlocked binding/canonicalization lane. PostgreSQL persistence certification remains dependency-gated and unleased.

Supervisor: `supervisor-main`  
Control branch: `supervisor/TASK-0032`  
Shipping integration branch: `ship/week-1`  
Parent task: `TASK-0032`  
Branch creation baseline: `3bdc9e797cdded1073600dc7945381baa53a9e0f`  
Active writers: `2` (1 Supervisor + 1 dependency-unlocked worker)  
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
- Shared database migrations remain Supervisor-owned. Workers must not write `database/migrations/**`.

<!-- WORKSTREAM_TABLE_START -->
| Merge group | Workstream | Module/capability | Slot | Assigned agent | Start status | Branch | PR merge strategy | Resume/sync strategy |
|---:|---|---|---|---|---|---|---|---|
| 5 | WS-0032-SUPERVISOR-CONTROL | TASK-0032 control + Supervisor-owned canonical persistence schema + merge coordination | `occupied` | `supervisor-main` | `active` | `supervisor/TASK-0032` | squash | merge latest ship/week-1 before resume |
| 10 | WS-0032-CONTENT-MODEL | Canonical content identities, immutable versions and typed provider-neutral document tree | `open` | — | `completed` | `worker-1/TASK-0032` | squash | merged as PR #255 |
| 20 | WS-0032-TEMPLATE-COMPONENTS | Template/component identities, immutable versions and explicit dependency references | `open` | — | `completed` | `worker-2/TASK-0032` | squash | merged as PR #257 |
| 30 | WS-0032-BINDINGS-CANONICALIZATION | Typed variables/localization, canonical hashes, dependency resolution and snapshot identity | `occupied` | `worker-task0032-bindings` | `active` | `worker-3/TASK-0032` | squash | merge latest ship/week-1 before implementation |
| 40 | WS-0032-PERSISTENCE-CERTIFICATION | Database repositories plus PostgreSQL/adversarial isolation and immutability certification | **OPEN** | — | `staged` | `worker-4/TASK-0032` | squash | start only after merge groups 10, 20 and 30 |
<!-- WORKSTREAM_TABLE_END -->

## Dependency-safe merge waves

1. **Wave 0 — control activation:** merge this registry-only control PR into `ship/week-1` after the exact-head Shipping Fast Gate passes. No TASK-0032 product implementation belongs in the activation PR.
2. **Wave 1 — contracts in parallel:** Supervisor adds only the shared canonical persistence migration, while Content and Templates workers implement their non-overlapping domain contracts. Each lane first merges the latest `ship/week-1` and submits only its leased paths.
3. **Wave 2 — binding and canonicalization:** after both domain lanes are integrated and green, free a writer slot and lease `WS-0032-BINDINGS-CANONICALIZATION`. Implement typed variables/localization, deterministic canonical hashes, version-aware dependency resolution and render-input snapshot identity.
4. **Wave 3 — persistence and adversarial certification:** after Wave 2 integrates, lease `WS-0032-PERSISTENCE-CERTIFICATION`. Implement repositories against the Supervisor-owned schema and prove tenant isolation, immutable-version enforcement, replay/conflict behavior, optimistic concurrency, dependency-cycle rejection and deterministic hashes on PostgreSQL.
5. **Wave 4 — integration certification:** `ship/week-1` must pass full Application Foundation CI and AI Continuity after every integration push. Dependency-chain failures freeze only affected lanes.
6. **Final promotion:** promote a green `ship/week-1` baseline to protected `main`; require full protected-main Continuity, Application and Security Supply Chain evidence before TASK-0032 acceptance or TASK-0033 activation.

## Wave 1 integrated evidence

- PR #254 merged the Supervisor-owned canonical schema foundation as `da8a1844fc326a0396af14fa5070bc054c86acd3` after Shipping Fast Gate run `35391424318` passed.
- PR #255 merged the canonical Content model as `bca543f960f45ef1a1adb62a151789539f15aa80` after exact-head Shipping Fast Gate run `35392209129` passed.
- PR #257 merged the Templates/Component model as `471cf7155fb260b104fb938d2391298b5d16d02e` after exact-head Shipping Fast Gate run `35392426901` passed.
- Merge groups 10 and 20 are dependency-complete and their worker leases are released.

## Exact next action

Merge this Wave 2 control transition into `ship/week-1` after a green exact-head Shipping Fast Gate. Then fast-forward/reset `worker-3/TASK-0032` to that integration head and implement only typed variables/localization binding, deterministic canonicalization/hash semantics, exact version dependency resolution and render-input snapshot identity. Keep `WS-0032-PERSISTENCE-CERTIFICATION` unleased until Wave 2 is integrated and green.
