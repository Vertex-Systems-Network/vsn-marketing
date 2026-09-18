# AI-Native Parallel Plan — TASK-0033 Canonical Asset Library

Status: **active — Wave 1 immutable originals + Supervisor schema**. TASK-0033 is the active PHASE-06 task. Two bounded writers are active: Supervisor-owned persistence schema plus the immutable-original/domain lane; dependent variant/storage/certification lanes remain staged.

Supervisor: `supervisor-main`  
Control branch: `supervisor/TASK-0033`  
Parent task: `TASK-0033`  
Branch creation baseline: `539199cb6a698dfc4fe1bc84980ca5b77257822f`  
Active leases: `2`  
Shipping Mode writer cap: `5`  
Repository hard cap: `12`  
Merge strategy: `squash`  
Completion signal: `Work Done and Submitted`

## Frozen implementation invariants

- Assets owns canonical media/creative assets, variants, metadata, provenance, rights and transformations; Content references assets but does not own binary/media lifecycle.
- Original asset binary state is immutable and hash-verifiable. Replacement/edit operations create new originals/versions or derived variants with explicit lineage.
- Ingestion validates observed content against declared metadata and uses bounded size/media constraints; malformed, unsafe or foreign-workspace inputs fail closed.
- Variants use normalized typed transform specifications pinned to exact source identity plus processor/version identity; equivalent requests are replay/idempotency safe.
- Transformation execution is allowlisted and resource-bounded with no arbitrary shell/code execution, ambient secrets, path traversal, unrestricted filesystem/network access or uncontrolled remote fetch.
- S3-compatible object storage remains workspace isolated; storage keys/identities are not trusted as authorization boundaries by themselves.
- Provider/channel media requirements are versioned capability evidence only. Provider upload IDs and publishing payloads are not canonical asset state.
- TASK-0033 does not implement TASK-0034 visual/code editor, sanitizer, renderer/compiler, preview runtime or PHASE-07 publishing.

<!-- WORKSTREAM_TABLE_START -->
| Merge group | Workstream | Module/capability | Slot | Assigned agent | Start status | Branch | PR merge strategy | Resume/sync strategy |
|---:|---|---|---|---|---|---|---|---|
| 5 | WS-0033-SUPERVISOR-CONTROL | Own TASK-0033 staged activation, shared asset persistence schema, dependency-safe merge sequencing and final acceptance without pulling TASK-0034 editor/compiler/render execution or provider publishing forward. | `occupied` | `supervisor-main` | `active` | `supervisor/TASK-0033` | squash | merge latest ship/week-1 before resume |
| 10 | WS-0033-ASSET-ORIGINALS | Implement workspace-scoped canonical asset identity, immutable original metadata, hash/media validation, provenance/rights and ingestion domain contracts without persistence or transform execution. | `occupied` | `worker-task0033-originals` | `active` | `worker-1/TASK-0033` | squash | merge latest ship/week-1 before resume |
| 20 | WS-0033-VARIANT-CONTRACTS | Implement normalized deterministic variant/transformation specifications, lineage, processor identity and replay-safe transformation planning contracts without executing external processors. | **OPEN** | — | `staged` | `worker-2/TASK-0033` | squash | merge latest main before resume |
| 30 | WS-0033-STORAGE-PERSISTENCE | Implement PostgreSQL repositories and S3-compatible object-storage adapters for immutable originals and deterministic variants with workspace isolation, idempotency and bounded storage access. | **OPEN** | — | `staged` | `worker-3/TASK-0033` | squash | merge latest main before resume |
| 40 | WS-0033-CERTIFICATION | Certify tenant isolation, immutable originals, content/metadata integrity, idempotent ingestion/transforms, variant lineage, failure/concurrency behavior and safe S3-compatible storage boundaries. | **OPEN** | — | `staged` | `worker-4/TASK-0033` | squash | merge latest main before resume |
<!-- WORKSTREAM_TABLE_END -->

## Dependency-safe waves

1. **Transition/staging:** complete TASK-0032, activate TASK-0033 canonically, pre-create branches, keep zero leases.
2. **Wave 1:** Supervisor-owned persistence schema plus immutable original/domain contracts.
3. **Wave 2:** deterministic variant/transformation contracts after original identity contracts are stable.
4. **Wave 3:** PostgreSQL + S3-compatible persistence/storage after domain and transform contracts integrate.
5. **Wave 4:** adversarial/security certification and final exact-head acceptance before TASK-0034.

## Exact next action

Merge this bounded Wave 1 activation into `ship/week-1` only after exact-head Shipping Fast Gate passes. Then fast-forward `supervisor/TASK-0033` and `worker-1/TASK-0033` to the certified integration head and implement only the Supervisor-owned asset foundation migration plus immutable-original/domain contracts. Keep variant execution, storage persistence, certification, TASK-0034 editor/compiler/render runtime and provider publishing out of scope until their dependencies are explicitly unlocked.
