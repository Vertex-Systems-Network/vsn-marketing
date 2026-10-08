# VSN Marketing

AI-native, provider-agnostic marketing operating system under active development.

## Development progress

<!-- AI_PROGRESS_SNAPSHOT roadmap=91.5 phase=90 current_phase=PHASE-14 active_task=TASK-0087 milestone=TASK-0087-PHASE14-CERTIFICATION status=IN_PROGRESS -->

> PHASE-08 and PHASE-09 tasks are complete. TASK-0051 AC-5 uses independently validated RBT-052 v6 raw evidence: Redis queue and PostgreSQL-backed pinned five-node graph with canonical consent/suppression gates and a synthetic no-op provider. The 200-job four-worker control completed in two passes with 2.774s p95 queue age; eight workers took five passes with 64.266s p95. These isolated-harness figures do not establish production limits or provider latency. PR #447 added scheduled bounded due-work recovery. PR #449 certified the accessible journey builder, simulator, lifecycle and timeline UX. PR #450 certified the PHASE-09 matrix on exact head and protected main. PR #451 terminal closure carrier passed exact-head and resulting-main checks. PHASE-10 TASK-0055 gateway contracts passed PostgreSQL contention and exact-head/main gates in PRs #453–#454. TASK-0056 context and TASK-0057 typed tools are accepted; PR #457 repaired PostgreSQL fork isolation and passed exact-head/main gates. TASK-0058 specialist candidate runtime is accepted via PR #458 and full exact-head/main gates. TASK-0059 creative drafts are accepted through PR #459 and full exact-head/main checks. TASK-0060 red-team is accepted through PR #460 and full exact-head/main gates. TASK-0061 certification is accepted through PR #461 and full exact-head/main checks. PHASE-10 offline architecture is complete; live provider routes remain disabled. PHASE-11 TASK-0063 offline assignment foundation passed PR #466 exact-head and resulting-main gates. TASK-0064 campaign matrix passed PR #467 exact-head and resulting-main gates. TASK-0065 offline optimization passed PR #468 exact-head and resulting-main gates. TASK-0066 offline statistical guardrails passed PR #469 exact-head and resulting-main gates. TASK-0067 offline certification passed PR #470 full exact-head and resulting-main gates; no experiment traffic is enrolled. PHASE-12 TASK-0068 research and TASK-0069 canonical fact/privacy foundation are accepted through PRs #472/#473; TASK-0070 behavior reports are accepted through PR #474 and full resulting-main gates; TASK-0071 revenue/attribution is accepted through PR #475 and full resulting-main gates; TASK-0072 operator reports, schedules and guarded explanations are accepted through PR #476 full exact-head and resulting-main gates; TASK-0073 source reconciliation is accepted through PR #477 full exact-head and resulting-main gates; TASK-0074 bounded analytics certification passed PR #478 and full protected-main gates; PHASE-12 is complete. The source verifier remains unbound and production readiness requires separate evidence. Source completeness, live effectiveness and production scale remain unproven.

> Canonical progress comes from [`.ai/state/CURRENT-STATE.yaml`](.ai/state/CURRENT-STATE.yaml) and [`.ai/roadmap/ROADMAP.yaml`](.ai/roadmap/ROADMAP.yaml). README is the required human-readable mirror when the canonical progress marker changes; evidence-only state updates do not force dashboard churn.

**Overall roadmap progress: 91.50%**<br />
**Current phase: PHASE-14 — 90.00%**<br />
**Last completed task: TASK-0086**<br />
**Current milestone: TASK-0087-PHASE14-CERTIFICATION — IN_PROGRESS**

```text
 Overall  [██████████████████░░] 91.50%
Phase 14 [██████████████████░░] 90.00%
```

The deterministic roadmap percentage is calculated from completed task weights. PHASE-07 is certified by TASK-0041 final acceptance / PR #405. TASK-0042 remains an unmaterialized ID gap; PHASE-08 starts at TASK-0043; TASK-0043 through TASK-0047 and PHASE-08 are complete. PHASE-09 has TASK-0048 through TASK-0053 complete.

### Phase / module progress

| Phase | Weight | Main modules / capability | Status | Progress |
|---|---:|---|---|---:|
| PHASE-00 | 4% | Architecture, AI continuity, project governance | ✅ Complete | 100% |
| PHASE-01 | 7% | Core, Identity, Tenancy, RBAC, Audit, Security foundation, queues/runtime | ✅ Complete | 100% |
| PHASE-02 | 7% | Contacts, identities, companies, lists/tags, Consent, Events | ✅ Complete | 100% |
| PHASE-03 | 7% | Providers, Connectors, Webhooks, Integrations, provider security baseline | ✅ Complete | 100% |
| PHASE-04 | 7% | Delivery, routing, throttling, idempotency, retry/failover, SLOs | ✅ Complete | 100% |
| PHASE-05 | 6% | Domains, sender identity, Suppressions, Deliverability | ✅ Complete | 100% |
| PHASE-06 | 6% | Templates, Content, Assets, creative/editor pipeline | ✅ Complete | 100% |
| **PHASE-07** | **7%** | **Campaigns, Publishing, approvals, scheduling, unified calendar, operator UX** | ✅ **Certified on protected main via TASK-0041 / PR #405** | **100.00%** |
| **PHASE-08** | **5%** | **Segmentation, deterministic AST/compiler, AI proposal and preview UX** | ✅ **Complete** | **100.00%** |
| **PHASE-09** | **7%** | **Journeys, automation runtime, triggers/waits/branches/replay** | ✅ **TASK-0053 certified** | **100.00%** |
| **PHASE-10** | **8%** | **AI gateway, memory/context, typed tools, agents, red-team** | ✅ **Offline architecture certified; live activation gated** | **100.00%** |
| **PHASE-11** | **5%** | **Experiments, variants, statistical guardrails, adaptive optimization** | ✅ **TASK-0062–0067 offline architecture certified** | **100.00%** |
| PHASE-12 | 6% | Analytics, funnels, cohorts, Attribution, revenue/LTV, data quality | ✅ TASK-0068–0074 bounded certification via PR #478 | 100% |
| PHASE-13 | 5% | Omnichannel Connectors, social Publishing, Community, listening | ✅ TASK-0081 bounded certification complete | 100% |
| PHASE-14 | 5% | Connector Factory, generated adapter candidates, sandbox/security gates | 🔄 TASK-0082–0086 accepted; TASK-0087 certification active | 90% |
| PHASE-15 | 4% | Bounded autonomous marketing loops, budgets, kill switch, canaries | ⏳ Planned | 0% |
| PHASE-16 | 4% | Enterprise identity/governance, Billing, white-label, residency, DR | ⏳ Planned | 0% |

### PHASE-07 closeout evidence (historical)

PR #402 exact head `34b1a0a2632218cd87ae070547f16a20dc76ba5a` passed AI Continuity Guard, Application Foundation CI, Security Supply Chain CI and all applicable E2E/integration checks before merging as `ed7644bddabfe9eea4128a3c607e9cb2c9d1a20e`; it staged the exclusive TASK-0041 Operator UX lane.

PR #403 merged exact worker head `391f43b3e7ee720be878294009a5993c163da685` into `ship/week-1` as `7f43eda18cc95ab90ea0b56207e67476e7433fab`. Its exact worker Shipping Fast Gate passed. Product-bearing integration Application Foundation CI passed backend tests, infrastructure integration, architecture checks, PHP static analysis/formatting, frontend typecheck/unit tests/build, PHP 8.3 compatibility, and Playwright smoke.

The integration push correctly exposed a missing global continuity-ledger handoff. PR #404 synchronized PR #402's current Lane-4 authority, appended the hash-chained checkpoint, and passed its exact Shipping Fast Gate. Resulting integration head `0733c40eead4038e2c1d7f19a50df23b88aaac6a` passed Continuity, Application Foundation and Shipping Fast Gate. Product/test files are unchanged from the fully tested `7f43eda` snapshot.

TASK-0041 AC-1..AC-8 were accepted by PR #405. Its exact head passed Continuity, Application Foundation and Security Supply Chain before merging as `87e65b1d458a79f925376d4cf49792d3771ef192`; resulting-main gates passed and were reconciled at `6d0269bfe9b44b0623fbe1eb0e4d59fd1462e115`.

The stale preplanned TASK-0042 reservation remains an unmaterialized identifier gap because PR #405 completed PHASE-07 certification. TASK-0043 begins PHASE-08; TASK-0043 through TASK-0047 and PHASE-08 are complete. PHASE-09 tasks TASK-0048 through TASK-0053 are complete.

### Current execution snapshot

PHASE-08 research and TASK-0042 plan-drift reconciliation are recorded in `.ai/research/PHASE-08/TASK-0043-RESEARCH.md`. TASK-0044 AST/compiler, TASK-0045 natural-language proposal compiler, TASK-0046 bounded preview/count UX, and TASK-0047 certification are complete. PHASE-14 is active; TASK-0083 connector planning and TASK-0084 generated candidates are accepted. TASK-0085 validation and fail-closed canary-promotion gates were accepted from merged PR #505. TASK-0086 compatibility scoring, dated deprecation provenance, rollback/disable and tenant-scoped lifecycle evidence were implemented in PR #517 and passed exact-head and resulting-main gates; provider or production activation is not claimed. TASK-0087 Phase-14 certification is active. Deterministic roadmap progress is 91.50%. PHASE-10 TASK-0054 research and TASK-0055 gateway are complete; TASK-0056 context and TASK-0057 typed tools are accepted. TASK-0058 specialist candidate runtime is accepted. TASK-0059 creative drafts are accepted. TASK-0060 red-team is accepted. TASK-0061 certification is accepted. PHASE-10 offline architecture is certified100%; PHASE-11 TASK-0062 research is accepted on PR #465 full exact-head and resulting-main control gates; TASK-0063 offline assignment foundation passed full exact-head and resulting-main gates in PR #466. TASK-0064 campaign matrix passed exact-head and resulting-main gates in PR #467. TASK-0065 offline optimization passed PR #468 exact-head and resulting-main gates. TASK-0066 statistical guardrails passed PR #469 exact-head and resulting-main gates. TASK-0067 offline certification passed PR #470 full exact-head and resulting-main gates; production enrollment remains disabled. See [.ai/research/PHASE-10/PHASE-10-CERTIFICATION.md](.ai/research/PHASE-10/PHASE-10-CERTIFICATION.md) for the source/test/run matrix and pending live activation gates.

### README progress-sync contract

README synchronization is marker-driven. When `.ai/state/CURRENT-STATE.yaml` changes roadmap/phase percentage, current phase, active task, current milestone, or milestone status, the same PR must update this README. The machine marker, headline metrics, text progress bars, current task/milestone labels, and the matching phase/module table row are one atomic progress surface; partial or stale synchronization is governance drift and must be repaired automatically. Evidence-only changes such as exact-head run IDs, quality evidence, snapshot-basis SHA, queue/Runner evidence, or other non-marker metadata do not require README churn. `tools/supervisor_contract.py` validates the canonical marker and PR-level marker-change rule.

## Delivery estimate assumptions

Delivery timing depends on exact-head CI, production-representative recovery/reconciliation evidence, provider policies, security gates, accessibility, AI evaluation/red-team work, and enterprise recovery/compliance requirements. The roadmap is research-first, so estimates should be recalculated after each phase certification rather than treated as fixed deadlines.

## For coding agents and contributors

Agent instruction revision: `parallel-v2.8.8-no-legacy-stop`  
Agent instruction fingerprint: `cddec098def6a24e88483216a27dee590fe163c47d8e0eb53ac0282df4808d7b`

**URL-only repository entry:** A message containing only this repository's GitHub URL is read-only: reconcile current repo state and show shuffled numbered next actions; do not mutate until a later numeric selection is revalidated.

**5-hour continuous Workspace mode:** mutating `start`/`continue`/`resume` requests do not present development choices. The Supervisor reconciles live repository truth, selects the highest-priority safe canonical action itself, and proceeds. CI observation uses bounded backoff: up to 4 normal exact-head observations per gate cycle and up to 12 only with a durable material-transition exception; ordinary CI duration, validator failures, and transient tool failures must not become user confirmation prompts. After a mutating start/continue/resume, the Supervisor follows [`.ai/parallel/WORKSPACE-5H-CONTINUOUS-BATCH.md`](.ai/parallel/WORKSPACE-5H-CONTINUOUS-BATCH.md) for up to 300 minutes / available Workspace credits. Generic start/continue/resume defaults to maximum safe canonical roadmap-frontier progress for the full available credit window, automatically advancing dependency-ready canonical tasks and phases while credit remains; explicitly narrower PR/task/phase/audit instructions stay narrow. A single user turn may execute multiple substantial implementation slices and multiple PR/CI/merge cycles; “substantial batch” is a work-slice sizing concept, not a stop boundary. Routine repository decisions do not return to the user for repeated consent: same-scope implementation, tests, CI diagnosis/repair, stale/duplicate PR handling, ordinary merge conflicts, and verified green-head merges continue automatically. Task, PR, phase, and individual substantial-slice completion are not handoff boundaries while another safe canonical successor exists. Intermediate next-action handoffs are suppressed until credits/window end or a documented stop condition leaves no safe ready roadmap work. A genuine human-only authority/safety boundary blocks only that action: record and quarantine it, continue every other safe canonical frontier item, and surface it only when it is the sole remaining safe path. Production/provider, secrets, billing, destructive data/migration, branch-protection weakening and deployment/release authority remain separate.

**Autonomous next-action routing:** For mutating development, the user is not asked to choose the next technical action. Merged/closed active PR pointers are stale resume metadata, not blockers: live GitHub truth automatically supersedes them and the Supervisor continues to the current canonical successor. The Supervisor revalidates compact state, exact main, Issues/PRs, coordination and Runner evidence, then selects and executes the highest-priority safe canonical route using plan priority, existing architecture/contracts, least privilege, reversibility and tests. Interactive/numbered options are reserved for URL-only read-only repository entry or when the user explicitly asks to see choices. At a genuine sole remaining human-only boundary, report the single exact external action required and the canonical resume point instead of presenting a menu.

**Nonterminal progress updates:** A progress/status message is never a stop action inside an active mutating batch. If the current turn can still execute tools, the Supervisor must continue after the update. Pending CI, an open PR, a checkpoint, or “Next: once CI finishes…” are not valid terminal reasons. Before any final reply, re-check exact main, active PR checks, independent safe work, fallback tool paths, and remaining execution capacity; finalize only when a documented stop condition is actually true and no executable safe action remains.

VSN uses a **Supervisor-controlled multi-agent workflow**. The agent operating the main-repository context is the Supervisor; protected `main` is not a scratch branch. Worker and Supervisor implementation happens on pre-created dedicated branches/worktrees listed in [`.ai/parallel/AI-NATIVE-PLAN.md`](.ai/parallel/AI-NATIVE-PLAN.md).

**Week-1 Shipping Mode is active.** Sprint feature/workstream PRs use `ship/week-1` as the integration target, `ai_parallel.py sync-check` validates that shipping baseline for workers, and PRs must pass `Shipping Fast Gate`. Independent leased lanes can keep coding from the last green integration baseline while a sibling merge is still verifying, but must sync the latest required green integration head before submission/merge/dependency consumption. Work is promoted to `main` only from a green integration baseline. Full protected-main application, security and governance gates remain mandatory. The activation-time `TASK-0026` workstreams are grandfathered as a drain wave: existing occupied slots may finish, but no new writable slot may be added or reassigned above the five-writer shipping cap; the cap becomes hard after TASK-0026 transitions. See [`.ai/parallel/WEEK-1-SHIPPING-PLAN.md`](.ai/parallel/WEEK-1-SHIPPING-PLAN.md).

**Strict plan-following, change-aware CI, Development Acceleration v2.8, and 5-hour continuous Workspace execution are mandatory.** Work is wave-oriented: batch dependency-ready disjoint leases into one control carrier when distinct real agents are available; independent leased lanes may code from the last green shipping baseline while a newer sibling integration head verifies; synchronize latest required green `ship/week-1` before submission/merge/dependency consumption; worker PRs use Shipping Fast Gate; terminal worker evidence rides into the next substantial wave-control/promotion PR; full protected-main Application + Security gates remain concentrated at promotion/final-acceptance/security boundaries. Per-lane protected-main orchestration PRs are not the default. No acceleration may fake agents, overlap write paths, consume pending/failed integration, or weaken permissions/security. Pure `.ai/**`, `docs/**`, `README.md`, and `AGENTS.md` diffs default to lightweight control CI; unknown/non-control paths fail closed to full Application + Security CI. Add the exact standalone PR line `CI-Mode: full` whenever a control-only certification/release/security milestone still requires full gates. Runner optimization tasks remain deferred in the persistent benchmark backlog and are not executed opportunistically.

**Protected-main observation is non-recursive.** `observed_main_sha` is a snapshot-basis anchor, not a self-updating HEAD pointer. An anchor descendant containing only approved durable reconciliation surfaces is already current and must not trigger another state-only PR; material drift still fails closed.

Before modifying the repository, read this README, [`AGENTS.md`](AGENTS.md), [`.ai/13-PARALLEL-DEVELOPMENT.md`](.ai/13-PARALLEL-DEVELOPMENT.md), and the machine registries under [`.ai/parallel/`](.ai/parallel/). Then run the full startup sequence from `AGENTS.md`, including:

```bash
python tools/ai_txn.py recover
python tools/ai_txn.py validate
python tools/ai_state.py recover
python tools/ai_state.py validate
python tools/ai_journal.py validate
python tools/supervisor_contract.py validate
python tools/runner_benchmark.py validate
python tools/ai_policy.py
python tools/ai_parallel.py validate
python tools/ai_context.py manifest
python tools/ai_state.py status
python tools/ai_journal.py status
python tools/ai_parallel.py status
python tools/ai_parallel.py batch-status
python tools/ai_parallel.py sync-check
```

### Parallel agent rules

- **Branch-first:** for a declared parallel cycle, the Supervisor's first repository mutation is creating every worker branch and its own `supervisor/...` branch. No planning/code write precedes branch creation.
- **One writer, one lane:** writable parallel work requires a registered workstream, assigned logical agent, dedicated branch/worktree, exclusive lease, dependency readiness, and non-overlapping write paths.
- **Shared files:** workers do not edit Supervisor-owned state/tasks/roadmap/parallel registries, dependency manifests, workflows/config/routes, migrations, Core, or canonical connector contracts.
- **Completion:** when a worker or the Supervisor finishes its assigned workstream, its non-draft PR must contain `Workstream: <ID>` and the exact standalone signal **`Work Done and Submitted`**.
- **Supervisor interrupt:** a submitted workstream PR preempts optional Supervisor module work. The Supervisor pauses, reviews, merges only approved/current-baseline/green changes, synchronizes its own branch, then resumes.
- **Merge alert:** after every workstream merge the Supervisor posts this exact alert to GitHub issue [#43](https://github.com/Vertex-Systems-Network/vsn-marketing/issues/43) and every other open registered workstream PR: **`New changes have been merged — please merge these changes into your branch first, then resume your own work.`**
- **Resume only after sync:** during Week-1 Shipping Mode, every alerted sprint agent must merge/pull latest `ship/week-1`, rerun affected fast checks, and only then resume. Outside Shipping Mode, the baseline remains latest `main` plus `python tools/ai_parallel.py sync-check`.

The active control-plane slice has one occupied Supervisor activation lane and one OPEN QA capacity slot. Only `supervisor-main` is currently assigned/leased. The QA branch must merge the activation main and pass sync-check before it can be leased for TASK-0023 implementation evidence.

The Persistent Supervisor is independent standing infrastructure: `.github/workflows/persistent-supervisor.yml` continues to observe repository state between interactive sessions, but its review-ready marker is triage only and never approval or merge authority.

**Instruction sync is mandatory:** whenever canonical agent-working instructions change, the same PR must review/update this section, bump the instruction revision when behavior changes materially, recompute `.ai/parallel/CONTROL.yaml`'s deterministic fingerprint, and copy the same revision/fingerprint here. `python tools/ai_parallel.py validate` and CI fail closed on drift.

### New agent onboarding

Every new development agent begins from `main` and runs:

```bash
python tools/ai_parallel.py onboarding-check --branch main
```

The Supervisor checks the AI-Native Plan for an open slot and assigns one deterministically with:

```bash
python tools/ai_parallel.py onboard --agent <agent-name> --agent-start-branch main
```

The assignment marks the slot occupied, records the agent and start status, and refreshes the AI-Native Plan. If all worker/research/QA slots are occupied, onboarding stops immediately and the Supervisor must reply exactly: **`Go Home Come Back Next Time`**. The rejected agent receives no branch assignment, lease, or work.

Dynamic agent/slot assignments update orchestration state but do not require a README fingerprint bump unless working instructions themselves change.

The active task, exact next action, progress, blockers, tests, roadmap, architecture rules, last checkpoint, workstreams, leases, and merge protocol live under [`.ai/`](.ai/).

## Architectural direction

- Modular monolith first; event-driven boundaries.
- Provider-neutral core with SMTP/API/mailbox/marketing adapters.
- Canonical customer, consent, event, message, template, campaign and journey models.
- Deterministic consent/suppression/security/approval gates.
- Specialized AI agents behind typed tools and structured outputs.
- Future AI Connector Factory for controlled provider integration generation.

Implementation sequence is defined in [`.ai/roadmap/MASTER-ROADMAP.md`](.ai/roadmap/MASTER-ROADMAP.md).

Phase 11 terminal closure PR #471 merged after full exact-head gates. Phase 12 tasks TASK-0068–0074 are certified. Phase 13 tasks TASK-0075–0081 are registered; TASK-0075 research passed PR #480; TASK-0076 guarded offline messaging is the accepted dependency; TASK-0077 social publishing is active.
