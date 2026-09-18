# AI-Native Parallel Plan — TASK-0033 Canonical Asset Library

Status: **active — Wave 3 PostgreSQL + object-storage persistence**. TASK-0033 schema, immutable originals and deterministic variant contracts are integrated on `ship/week-1`. Two bounded writers remain active: Supervisor control plus the dependency-unlocked storage/persistence lane; final certification remains staged.

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
| 10 | WS-0033-ASSET-ORIGINALS | Implement workspace-scoped canonical asset identity, immutable original metadata, hash/media validation, provenance/rights and ingestion domain contracts without persistence or transform execution. | `open` | — | `completed` | `worker-1/TASK-0033` | squash | merged as PR #271 |
| 20 | WS-0033-VARIANT-CONTRACTS | Implement normalized deterministic variant/transformation specifications, lineage, processor identity and replay-safe transformation planning contracts without executing external processors. | `open` | — | `completed` | `worker-2/TASK-0033` | squash | merged as PR #275 |
| 30 | WS-0033-STORAGE-PERSISTENCE | Implement PostgreSQL repositories and S3-compatible object-storage adapters for immutable originals and deterministic variants with workspace isolation, idempotency and bounded storage access. | `occupied` | `worker-task0033-storage` | `active` | `worker-3/TASK-0033` | squash | merge latest ship/week-1 before resume |
| 40 | WS-0033-CERTIFICATION | Certify tenant isolation, immutable originals, content/metadata integrity, idempotent ingestion/transforms, variant lineage, failure/concurrency behavior and safe S3-compatible storage boundaries. | **OPEN** | — | `staged` | `worker-4/TASK-0033` | squash | merge latest main before resume |
<!-- WORKSTREAM_TABLE_END -->

## Integrated evidence

- PR #272 merged the Supervisor-owned TASK-0033 asset foundation schema into `ship/week-1` after exact-head Shipping Fast Gate success.
- PR #273 reconciled the Supervisor schema continuity ledger and merged as `6c7623362fb93f8a382a8a61e1e94d4eed0c731c` after Shipping Fast Gate `35404061334` passed; that reconciled ship baseline passed Continuity, Shipping Fast Gate and Application Foundation CI including PostgreSQL/Redis integration, PHP 8.3, E2E and foundation.
- PR #271 merged immutable asset-original/domain contracts as `8403527a1d2a85028f6b4c95755ad99368752dd9` after exact-head Shipping Fast Gate `35405401641` passed. The merge-triggered ship certification must be green before this Wave 2 activation merges.

## Integrated Wave 2 evidence

- PR #275 merged deterministic asset variant/transformation contracts as `093968ab954e65e75fc49a09fa61db2a155fe62a` after exact-head Shipping Fast Gate `35405938486` passed.
- The merge-triggered ship certification on `093968ab954e65e75fc49a09fa61db2a155fe62a` must be green across Continuity, Shipping Fast Gate and full Application Foundation CI before this Wave 3 activation merges.

## Dependency-safe waves

1. **Transition/staging:** complete TASK-0032, activate TASK-0033 canonically, pre-create branches, keep zero leases.
2. **Wave 1:** Supervisor-owned persistence schema plus immutable original/domain contracts.
3. **Wave 2:** deterministic variant/transformation contracts after original identity contracts are stable.
4. **Wave 3:** PostgreSQL + S3-compatible persistence/storage after domain and transform contracts integrate.
5. **Wave 4:** adversarial/security certification and final exact-head acceptance before TASK-0034.

## Exact next action

Merge this Wave 3 activation only after ship head `093968ab954e65e75fc49a09fa61db2a155fe62a` passes AI Continuity Guard, Shipping Fast Gate and full Application Foundation CI. Then fast-forward `worker-3/TASK-0033` to the resulting certified integration head and implement only workspace-isolated PostgreSQL repositories plus bounded S3-compatible object-storage adapters for immutable originals and deterministic variants. Preserve idempotency, exact source/spec/processor lineage and immutable history; do not execute arbitrary external transforms, start certification, or introduce TASK-0034 editor/compiler/render behavior.
