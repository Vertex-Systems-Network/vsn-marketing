# AI-Native Parallel Plan — TASK-0033 Canonical Asset Library

Status: **active — Wave 4 adversarial/security certification**. TASK-0033 schema, immutable originals, deterministic variant contracts and PostgreSQL/object-storage persistence are integrated and certified on `ship/week-1`. Two bounded writers remain active: Supervisor control plus the final certification lane.

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
| 30 | WS-0033-STORAGE-PERSISTENCE | Implement PostgreSQL repositories and S3-compatible object-storage adapters for immutable originals and deterministic variants with workspace isolation, idempotency and bounded storage access. | `open` | — | `completed` | `worker-3/TASK-0033` | squash | merged as PR #277 |
| 40 | WS-0033-CERTIFICATION | Certify tenant isolation, immutable originals, content/metadata integrity, idempotent ingestion/transforms, variant lineage, failure/concurrency behavior and safe S3-compatible storage boundaries. | `occupied` | `worker-task0033-certification` | `active` | `worker-4/TASK-0033` | squash | merge latest ship/week-1 before implementation |
<!-- WORKSTREAM_TABLE_END -->

## Integrated evidence

- PR #272 merged the Supervisor-owned TASK-0033 asset foundation schema into `ship/week-1` after exact-head Shipping Fast Gate success.
- PR #273 reconciled the Supervisor schema continuity ledger and merged as `6c7623362fb93f8a382a8a61e1e94d4eed0c731c` after Shipping Fast Gate `35404061334` passed; that reconciled ship baseline passed Continuity, Shipping Fast Gate and Application Foundation CI including PostgreSQL/Redis integration, PHP 8.3, E2E and foundation.
- PR #271 merged immutable asset-original/domain contracts as `8403527a1d2a85028f6b4c95755ad99368752dd9` after exact-head Shipping Fast Gate `35405401641` passed. The merge-triggered ship certification must be green before this Wave 2 activation merges.

## Integrated Wave 2 evidence

- PR #275 merged deterministic asset variant/transformation contracts as `093968ab954e65e75fc49a09fa61db2a155fe62a` after exact-head Shipping Fast Gate `35405938486` passed.
- The merge-triggered ship certification on `093968ab954e65e75fc49a09fa61db2a155fe62a` must be green across Continuity, Shipping Fast Gate and full Application Foundation CI before this Wave 3 activation merges.

## Integrated Wave 3 evidence

- PR #277 merged workspace-isolated PostgreSQL asset persistence plus bounded provider-neutral object-storage adapters as `b021af967fe8175ed41db842e9b4009cdd7bbe0e` after exact-head Shipping Fast Gate `35407184968` passed.
- Merge-triggered ship certification on `b021af967fe8175ed41db842e9b4009cdd7bbe0e` passed AI Continuity Guard `35407258325`, Shipping Fast Gate `35407258283`, and Application Foundation CI `35407258219`, including PostgreSQL/Redis integration, PHP 8.3, Playwright E2E, backend/architecture tests, static analysis, formatting, frontend tests and build.
- PostgreSQL integration proved immutable original/variant guards, workspace isolation, lineage, replay/idempotency and deterministic variant reuse. The storage boundary proved workspace-prefixed immutable object writes, hash verification and fail-closed unsafe/foreign key handling.
- Merge group 30 is dependency-complete and the storage worker lease is released.

## Dependency-safe waves

1. **Transition/staging:** complete TASK-0032, activate TASK-0033 canonically, pre-create branches, keep zero leases.
2. **Wave 1:** Supervisor-owned persistence schema plus immutable original/domain contracts.
3. **Wave 2:** deterministic variant/transformation contracts after original identity contracts are stable.
4. **Wave 3:** PostgreSQL + S3-compatible persistence/storage after domain and transform contracts integrate.
5. **Wave 4:** adversarial/security certification and final exact-head acceptance before TASK-0034.

## Exact next action

Merge this Wave 4 activation after a green exact-head Shipping Fast Gate. Then fast-forward `worker-4/TASK-0033` to the certified integration head and add only the registered PostgreSQL/adversarial and Security certification tests. Prove tenant isolation, immutable originals/variants, content/hash/metadata integrity, replay/idempotency, exact variant lineage, storage-boundary safety, malformed/foreign input rejection and failure behavior without adding arbitrary transform execution, TASK-0034 editor/compiler/render runtime, provider publishing or later-phase behavior.
