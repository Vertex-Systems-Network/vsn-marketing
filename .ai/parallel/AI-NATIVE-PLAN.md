# AI-Native Parallel Plan — TASK-0036 PHASE-06 Certification

Status: **protected-main promotion complete; final PHASE-06 acceptance reconciliation in progress**. TASK-0031 through TASK-0035 remain accepted on protected `main`; TASK-0036 phase-wide certification and the bounded Vitest dependency remediation are promoted to protected `main`. Supervisor remains active only for final acceptance reconciliation and maintenance-plan registration. Runner-related work is recorded but intentionally deferred to one coordinated benchmark batch.

Supervisor: `supervisor-main`  
Control branch: `supervisor/TASK-0036`  
Parent task: `TASK-0036`  
Branch creation baseline: `36120709c4d8e63160871894ea61fca67a386934`  
Active leases: `1`  
Shipping Mode writer cap: `5`  
Repository hard cap: `12`  
Merge strategy: `squash`  
Completion signal: `Work Done and Submitted`

## Frozen certification invariants

- TASK-0031 through TASK-0035 accepted evidence remains authoritative and may not be weakened merely to make phase certification pass.
- Canonical content, template, component, brand, asset, render and dependency identities remain exact-version pinned, immutable where published/execution-pinned, deterministic and provider-neutral.
- Workspace isolation is fail-closed across content, templates, reusable components, brand versions, assets, render/preview inputs and provider-template mappings/observations.
- Authored markup remains untrusted; script/event execution, traversal, unrestricted remote/network access, ambient secrets/credentials, renderer-resource escape and canonical mutation remain forbidden.
- Asset originals remain immutable with explicit rights/provenance and deterministic derived-variant lineage; destructive overwrite and untracked derivatives remain forbidden.
- Accessibility and representative responsive visual/client regression findings remain tied to exact source/render identities and bounded deterministic targets.
- Provider template/media requirements remain versioned/effective-dated capability evidence; external provider drift never becomes authoritative canonical VSN source.
- TASK-0036 is certification-only. It introduces no product behavior, provider publication, campaign approval, scheduling, publishing or other PHASE-07 execution capability.

<!-- WORKSTREAM_TABLE_START -->
| Merge group | Workstream | Module/capability | Slot | Assigned agent | Start status | Branch | PR merge strategy | Resume/sync strategy |
|---:|---|---|---|---|---|---|---|---|
| 10 | WS-0036-SUPERVISOR-CONTROL | Own PHASE-06 certification integration, shared-path coordination, shipping-baseline realignment, exact-head acceptance and terminal phase closeout without weakening TASK-0031 through TASK-0035 canonical, asset, rendering, brand or provider-neutral authority and without pulling PHASE-07 forward. | `occupied` | `supervisor-main` | `active` | `supervisor/TASK-0036` | squash | merge latest ship/week-1 before resume |
| 20 | WS-0036-PHASE-CERTIFICATION | Certify PHASE-06 exact-version reproducibility, workspace isolation, asset provenance and variant safety, rendering and preview security, accessibility and representative visual/client regression, and provider-template synchronization/drift boundaries without adding product behavior. | `open` | — | `completed` | `worker-1/TASK-0036` | squash | merged as PR #318 |
<!-- WORKSTREAM_TABLE_END -->

## Certified activation baseline

- TASK-0036 transition PR #316 merged on protected `main` as `36120709c4d8e63160871894ea61fca67a386934` after exact-head AI Continuity Guard `35541256162`, Application Foundation CI `35541256268`, and Security Supply Chain CI `35541256237` passed.
- Transition main head `36120709c4d8e63160871894ea61fca67a386934` passed AI Continuity Guard `35541384517`, Application Foundation CI `35541384419`, Security Supply Chain CI `35541384540`, Release Integrity `35541384552`, and OpenSSF Scorecard `35541384507`.
- A recursive Git-tree audit between prior certified ship head `affad05aa50c3ca105cc0a3cd8940a37079cd0a8` and transition main `36120709c4d8e63160871894ea61fca67a386934` found zero non-`.ai/**` differences; only nine canonical AI control/state files differed.
- `ship/week-1` was therefore safely realigned to `36120709c4d8e63160871894ea61fca67a386934`. The historical certified ship head is preserved at `archive/task-0035-final-ship` so append-only push verification retains an addressable base.
- Exact ship head `36120709c4d8e63160871894ea61fca67a386934` passed AI Continuity Guard `35541535176` after the historical-base reachability repair, Shipping Fast Gate `35541535218`, and Application Foundation CI `35541535198`, including PostgreSQL/Redis integration, PHP floor, Playwright E2E, foundation, static analysis, formatting, frontend tests and build.
- PR #317 activated the focused TASK-0036 certification lane and merged into `ship/week-1` as `fc6f34bdf19ae64cda8ae0451486fa4f7a1aa8f3` after exact-head Shipping Fast Gate `35541696540` passed.
- PR #318 merged the phase-wide integration/security certification tests as `87da7f71aa4d75d865ad9a01fbeb54b940ea5f98` after exact-head Shipping Fast Gate `35542440490` passed.
- Certified final ship head `87da7f71aa4d75d865ad9a01fbeb54b940ea5f98` passed AI Continuity Guard `35542490944`, Shipping Fast Gate `35542490931`, and Application Foundation CI `35542490958`, including PostgreSQL/Redis integration, PHP 8.3 compatibility, Playwright E2E, foundation, backend/architecture tests, static analysis, formatting, frontend tests and build.
- WS-0036-PHASE-CERTIFICATION is completed and released.
- Promotion head `1b6273711f0c51449b1fbe4aa39923e10571ae84` passed Security Supply Chain CI `35542862212`, but its dependency-audit log reported two moderate npm vulnerabilities. The material advisory is `GHSA-82fw-gwwq-j7x9` affecting Vitest/@vitest/mocker 3.x with path traversal / arbitrary file read; the job stayed green only because the repository used `npm audit --audit-level=high`.
- PR #321 activated the bounded security remediation under Supervisor-owned dependency/workflow paths and merged as `8cca66b7c05e53e0e9eb8b1a82cfd3e3a90ec985` after Shipping Fast Gate `35543969770` passed.
- PR #322 upgraded Vitest/@vitest/mocker to 5.0.0 with the reviewed lockfile delta, raised both npm audit gates to `moderate`, and merged as `dbff24fa9032b17b3853d9d12c9b92d30fcc9c8e` after exact-head Shipping Fast Gate `35544109634` passed with `found 0 vulnerabilities`.
- Security-remediated ship head `dbff24fa9032b17b3853d9d12c9b92d30fcc9c8e` passed AI Continuity Guard `35544171867`, Application Foundation CI `35544171851`, and Security Supply Chain CI `35544171748`; exact-head Composer audit found no advisories and npm audit at the MODERATE threshold found zero vulnerabilities. The temporary dependency/workflow Supervisor lease is now released.
- PR #320 promoted the fully certified/security-remediated TASK-0036 PHASE-06 baseline to protected `main` as `9b068a7b8b9abdd70dbfff6dbe1685a3099f5849`. The persistent Supervisor status reports AI Continuity Guard, Application Foundation CI, and Security Supply Chain CI successful on that exact protected-main head with no actionable blockers.


## Deferred runner benchmark registry

Runner work is intentionally separated from product/certification development and tracked in `docs/benchmarks/RUNNER-TASK-BENCHMARK-BACKLOG.md`.

Rules:

- Any task whose primary scope is GitHub Actions runner sizing/architecture, CI runner performance, runner cache/concurrency tuning, production-representative benchmark runner setup, or runner/toolchain optimization is appended to the registry instead of being executed opportunistically.
- Runner tasks remain `deferred` until an explicit coordinated runner-benchmark batch is activated. Normal product/security development continues independently.
- The batch must establish baselines before changing runner size, architecture, cache, concurrency, or workflow topology, and must compare before/after evidence on pinned source/workflow revisions.
- Security gates, branch protection, exact-head checks, secret handling, and benchmark environment isolation may not be weakened to improve runner numbers.
- GitHub-hosted CI timing must not be substituted for production SLO evidence. TASK-0024 production-representative benchmark evidence remains a distinct controlled environment.
- New runner-related tasks discovered in future work are appended to the registry with source, dependencies, measurements, and acceptance criteria. They are not silently executed.
- The runner registry is persistent across task/phase transitions; closing TASK-0036 does not discard or auto-execute it.

Current registry is seeded with application-CI, security-CI, Shipping Fast Gate, TASK-0024 production-representative benchmark-runner evidence, pending CodeQL runner/toolchain updates, and post-baseline runner-size/architecture/cache/concurrency evaluation.

## Exact next action

Merge this control-only runner-registry update after exact-head governance/application/security checks, then reconcile TASK-0036 final PHASE-06 acceptance on protected `main`. Do not execute runner benchmark tasks in this step; keep them deferred in the registry for one coordinated batch.
