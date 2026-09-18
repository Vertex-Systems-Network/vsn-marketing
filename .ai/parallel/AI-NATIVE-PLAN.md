# AI-Native Parallel Plan — TASK-0032 Canonical Content/Template Model

Status: **active — Wave 1 contract foundation**. TASK-0032 is the canonical PHASE-06 active task. Shipping Mode is limited to three current writers: Supervisor plus independent Content and Templates domain lanes. Binding/canonicalization and PostgreSQL certification remain dependency-gated and unleased.

Supervisor: `supervisor-main`  
Control branch: `supervisor/TASK-0032`  
Shipping integration branch: `ship/week-1`  
Parent task: `TASK-0032`  
Branch creation baseline: `3bdc9e797cdded1073600dc7945381baa53a9e0f`  
Active writers: `3` (1 Supervisor + 2 independent workers)  
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
| 10 | WS-0032-CONTENT-MODEL | Canonical content identities, immutable versions and typed provider-neutral document tree | `occupied` | `worker-task0032-content` | `active` | `worker-1/TASK-0032` | squash | merge latest ship/week-1 before resume |
| 20 | WS-0032-TEMPLATE-COMPONENTS | Template/component identities, immutable versions and explicit dependency references | `occupied` | `worker-task0032-templates` | `active` | `worker-2/TASK-0032` | squash | merge latest ship/week-1 before resume |
| 30 | WS-0032-BINDINGS-CANONICALIZATION | Typed variables/localization, canonical hashes, dependency resolution and snapshot identity | **OPEN** | — | `staged` | `worker-3/TASK-0032` | squash | start only after merge groups 10 and 20 |
| 40 | WS-0032-PERSISTENCE-CERTIFICATION | Database repositories plus PostgreSQL/adversarial isolation and immutability certification | **OPEN** | — | `staged` | `worker-4/TASK-0032` | squash | start only after merge groups 10, 20 and 30 |
<!-- WORKSTREAM_TABLE_END -->

## Dependency-safe merge waves

1. **Wave 0 — control activation:** merge this registry-only control PR into `ship/week-1` after the exact-head Shipping Fast Gate passes. No TASK-0032 product implementation belongs in the activation PR.
2. **Wave 1 — contracts in parallel:** Supervisor adds only the shared canonical persistence migration, while Content and Templates workers implement their non-overlapping domain contracts. Each lane first merges the latest `ship/week-1` and submits only its leased paths.
3. **Wave 2 — binding and canonicalization:** after both domain lanes are integrated and green, free a writer slot and lease `WS-0032-BINDINGS-CANONICALIZATION`. Implement typed variables/localization, deterministic canonical hashes, version-aware dependency resolution and render-input snapshot identity.
4. **Wave 3 — persistence and adversarial certification:** after Wave 2 integrates, lease `WS-0032-PERSISTENCE-CERTIFICATION`. Implement repositories against the Supervisor-owned schema and prove tenant isolation, immutable-version enforcement, replay/conflict behavior, optimistic concurrency, dependency-cycle rejection and deterministic hashes on PostgreSQL.
5. **Wave 4 — integration certification:** `ship/week-1` must pass full Application Foundation CI and AI Continuity after every integration push. Dependency-chain failures freeze only affected lanes.
6. **Final promotion:** promote a green `ship/week-1` baseline to protected `main`; require full protected-main Continuity, Application and Security Supply Chain evidence before TASK-0032 acceptance or TASK-0033 activation.

## Exact next action

Merge this registry-only TASK-0032 workstream activation into `ship/week-1` after a green Shipping Fast Gate. Then merge the latest integration baseline into `supervisor/TASK-0032`, `worker-1/TASK-0032` and `worker-2/TASK-0032`; implement only the Supervisor-owned shared schema, Content model and Templates/Component contracts in parallel. Keep binding/canonicalization and PostgreSQL certification unleased until their declared dependencies are integrated.
