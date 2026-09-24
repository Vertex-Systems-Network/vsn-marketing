# AI-Native Parallel Plan — TASK-0036 PHASE-06 Certification

Status: **final PHASE-06 acceptance**. TASK-0036 certification, security remediation, protected-main promotion, and the persistent deferred runner registry are all integrated on protected `main`. AC-1 through AC-8 are reconciled true while TASK-0036 intentionally remains `ready` until this Supervisor-only acceptance head passes its own exact-head gates. Runner work remains deferred to one coordinated benchmark batch.

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
- PR #320 promoted the fully certified/security-remediated TASK-0036 PHASE-06 baseline to protected `main` as `9b068a7b8b9abdd70dbfff6dbe1685a3099f5849`. That protected-main head passed AI Continuity Guard `35544603993`, Application Foundation CI `35544603884`, Security Supply Chain CI `35544603880`, Release Integrity `35544603927`, and OpenSSF Scorecard `35544603916`.
- PR #324 registered the persistent deferred runner benchmark backlog and merged as protected-main head `62add6effb833ee6d0835c41400e0daec4878ebf` after exact-head governance/application/security checks. That post-merge head passed AI Continuity Guard `35580129231`, Application Foundation CI `35580129198`, Security Supply Chain CI `35580129321`, Release Integrity `35580129191`, and OpenSSF Scorecard `35580129253`.


## Persistent execution resilience / timeout-avoidance policy

This policy is cross-phase and survives task transitions. The full standard is `docs/operations/AI-EXECUTION-RESILIENCE.md`; every future AI-Native plan revision must preserve or explicitly supersede it.

Resume authority is canonical state first: `.ai/state/CURRENT-STATE.yaml`, `.ai/state/LAST-CHECKPOINT.md`, `.ai/state/EXECUTION-JOURNAL.jsonl`, then current GitHub branch/PR/exact-SHA and CI evidence. Historical task/phase wording elsewhere in this plan is descriptive only and must never override those recovery sources on `continue` or after a timeout.

- Default interaction budget is **one substantial coherent batch** inside the active task. A batch may include implementation + focused tests + PR + bounded CI repair + exact-head verification + merge when green. Do not create standalone post-merge reconciliation PRs by default; carry trusted merge/run evidence into the next substantial PR unless a task/phase transition, release/security/recovery boundary, material drift, or no-safe-successor condition requires immediate reconciliation.
- External CI is a durable boundary, not a tight polling loop. After starting required gates, record PR/branch/exact SHA, read status, and normally perform at most one additional refresh in the same interaction unless a changed state requires diagnosis or one final read can close the milestone.
- If required CI remains in progress, stop at the durable branch/PR/SHA checkpoint. On the next `continue`, re-read GitHub state before doing any write.
- After a delivery timeout/interruption, never blindly replay branch/file/PR/merge/transition operations. Verify whether the prior operation already succeeded, then resume from actual repository state.
- Group related reads and avoid fetching logs unless a failed/ambiguous gate needs diagnosis.
- Preserve `.ai/state/CURRENT-STATE.yaml`, `.ai/state/LAST-CHECKPOINT.md`, journal/task/workstream state, branch/PR exact heads, and the Supervisor status as recovery evidence. Do not rewrite canonical state only to say that an external CI job is still running.
- End development interactions with a compact handoff: repo, active task/phase, completed milestone, PR/SHA, gate state, exact next action, and canonical progress when available.
- Security/correctness/release blockers may exceed the normal interaction budget for bounded diagnosis/remediation, but required gates may never be weakened for speed.
- Runner performance/size/cache/concurrency/toolchain work remains in the separate persistent Runner benchmark registry and is not activated merely to reduce message/tool timeout risk.

## Persistent strict plan-following / change-aware CI directive

This directive is cross-phase, non-optional, and survives every task transition. The Supervisor and all agents MUST follow the canonical execution order in `.ai/13-PARALLEL-DEVELOPMENT.md` before taking the next repository action.

- Recover/validate -> read canonical state/task/plan -> classify change -> execute one allowed milestone -> run class-appropriate checks -> exact-head PR gates -> merge -> re-read state -> only then register/transition the successor.
- `tools/ci_change_policy.py` is the machine classifier. Pure `.ai/**`, `docs/**`, `README.md`, and `AGENTS.md` changes default to lightweight governance/control CI; unknown or non-control paths fail closed to full Application + Security CI.
- `CI-Mode: full` as a standalone PR-body line is mandatory whenever a control-only diff is nevertheless a certification, release/promotion, security-sensitive acceptance, or other milestone whose contract explicitly requires full exact-head Application + Security gates.
- Required PR checks must not be disabled with workflow-level path filters. Heavy required jobs may be conditionally skipped at job level only after the fail-closed classifier succeeds.
- Product, dependency, workflow, tool, Docker, route, config, migration, test, and security-sensitive changes always run the full relevant gates.
- Runner benchmark work is never substituted for CI. Runner sizing/performance/cache/concurrency/toolchain optimization is appended to the persistent RBT backlog and executed only in the explicit coordinated final Runner batch, except for the documented blocker-escalation rule.
- Agents may not improvise a faster order, silently activate a later task, repeatedly poll CI, or opportunistically execute Runner benchmark items. A bounded security/correctness exception must be evidence-backed and recorded.

## Non-recursive protected-main observation directive

`observed_main_sha` is a snapshot-basis anchor. It MUST NOT be recursively rewritten merely because a durable state reconciliation PR changed protected-main HEAD.

At resume, validate live main against the anchor. Exact equality is current. A descendant whose entire diff is limited to approved durable reconciliation surfaces is `self_reconciliation_descendant`, is also current, and MUST NOT trigger another reconciliation PR. Any non-ancestor relationship or material path drift is fail-closed and must be reconciled before writable work.

## Persistent durable Supervisor resume directive

The Durable AI Engineering Supervisor contract in `.ai/13-PARALLEL-DEVELOPMENT.md` is mandatory across every task and phase. On every resume: compact state -> exact main -> open Issues -> open PRs -> deterministic claims/coordination queue -> machine Runner Benchmark -> only then new work. Fast Batch Development Mode advances one substantial coherent batch per turn by default, while unrelated tasks and guarded task/phase transitions remain separate. CI/status polling is bounded to one consolidated refresh unless a recorded safety exception applies.

The machine coordination queue is `.ai/coordination/OPEN-WORK-QUEUE.yaml`; the machine Runner Benchmark is `.ai/runner/RUNNER-BENCHMARK.yaml`. These registries never override live GitHub/runtime evidence and never grant production/provider/destructive execution authority. Compact state is bounded and the hash-chained execution journal is rolling with immutable historical archives.

No agent may report COMPLETE/BLOCKED/VERIFYING/WAITING without durable reconciliation. No agent may bypass an accepted actionable open Issue/PR, reuse authorization, infer PASS from pending/skipped work, or repeat an operation after a message timeout without repository verification.

## Persistent next-action option / interactive handoff directive

This directive is cross-phase, non-optional, and survives every task transition.

- Every development response ends with 1-3 valid next-action options derived from live repository truth. The canonical/accepted next work path is always included and marked Recommended, but it is not permanently tied to option 1.
- Prefer host-native clickable buttons/suggestion controls. A click submits the option's exact request payload and starts a new turn; it does not itself grant merge, provider, production, deployment, destructive, or consumed runtime authority.
- Shuffle the visible 1/2/3 numbering on every handoff when two or more valid options exist. If the previously selected action and number are known, that action must move to a different number on the next handoff. With one valid option, reuse is allowed. Number shuffling is presentation-only and never changes canonical action priority or authority.
- A URL-only repository message is a read-only entry request: reconcile repository truth and show shuffled next-action options, but do not mutate the repo until the user selects an option in a later turn and that selection is revalidated.
- Every selected option re-enters the full durable-resume sequence before execution. If repository state changed, replace the stale option with the current valid choices rather than blindly executing it.
- Active accepted Issues/PRs remain first. VERIFYING/WAITING work exposes verify/review/merge-if-green before unrelated development. Successor registration/transition appears only when canonical state explicitly permits it.
- If interactive controls are unavailable, show concise numbered fallback commands that can be sent unchanged so development can continue with one user action.
- Security gates, one-turn/one-milestone boundaries, deferred Runner rules, and exact-head protections are unchanged by this UI convenience.

## Persistent README progress synchronization directive

This directive is cross-phase, non-optional, and survives every task transition.

- Every durable bounded milestone PR that changes `.ai/state/CURRENT-STATE.yaml` also changes `README.md`.
- README progress values come only from canonical state/roadmap data; percentages are never estimated from conversational activity.
- The machine marker `AI_PROGRESS_SNAPSHOT` mirrors roadmap %, phase %, current phase, active task, current milestone and milestone status.
- CI/poll-only interactions with no repository state mutation do not create fake README commits; the next durable milestone syncs it.
- README progress sync is compatible with the non-recursive main-observation model because `README.md` is an approved self-reconciliation surface.
- Supervisor validation and PR-event validation fail closed on stale/missing README progress synchronization.

## Deferred runner benchmark registry

Runner work is intentionally separated from product/certification development and tracked in `docs/benchmarks/RUNNER-TASK-BENCHMARK-BACKLOG.md`.

Rules:

- Any task whose primary scope is GitHub Actions runner sizing/architecture, CI runner performance, runner cache/concurrency tuning, production-representative benchmark runner setup, or runner/toolchain optimization is appended to the registry instead of being executed opportunistically.
- Runner tasks remain `deferred` until an explicit coordinated runner-benchmark batch is activated. Normal product/security development continues independently.
- Immediate-execution exception: if a runner task is demonstrated to block security remediation, product correctness, a required exact-head verification gate, or protected-main/release acceptance, the Supervisor may explicitly promote that single item out of the deferred batch. The promotion must be narrow, evidence-backed, recorded in the runner registry with its reason/status, and must not be used for performance, convenience, or speculative optimization work.
- The batch must establish baselines before changing runner size, architecture, cache, concurrency, or workflow topology, and must compare before/after evidence on pinned source/workflow revisions.
- Security gates, branch protection, exact-head checks, secret handling, and benchmark environment isolation may not be weakened to improve runner numbers.
- GitHub-hosted CI timing must not be substituted for production SLO evidence. TASK-0024 production-representative benchmark evidence remains a distinct controlled environment.
- New runner-related tasks discovered in future work are appended to the registry with source, dependencies, measurements, and acceptance criteria. They are not silently executed.
- The runner registry is persistent across task/phase transitions; closing TASK-0036 does not discard or auto-execute it.

Current registry is seeded with application-CI, security-CI, Shipping Fast Gate, TASK-0024 production-representative benchmark-runner evidence, pending CodeQL runner/toolchain updates, and post-baseline runner-size/architecture/cache/concurrency evaluation.

## Exact next action

Run TASK-0036 final PHASE-06 acceptance on a Supervisor-only control PR with AC-1 through AC-8 true while task status remains ready; merge only after exact-head AI Continuity Guard, Application Foundation CI and Security Supply Chain CI pass. After merge, perform a separate guarded transition that completes PHASE-06 and explicitly registers/activates TASK-0037 as the PHASE-07 research-first successor. Keep all runner benchmark tasks deferred in the persistent runner registry until the coordinated runner batch is explicitly activated.
