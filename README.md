# VSN Marketing

AI-native, provider-agnostic marketing operating system under active development.

## Development progress

<!-- AI_PROGRESS_SNAPSHOT roadmap=53.75 phase=55 current_phase=PHASE-08 active_task=TASK-0046 milestone=PHASE-08-TASK-0046-AUDIENCE-PREVIEW-UX status=IN_PROGRESS -->

> PHASE-07 and TASK-0041 are complete on protected main `87e65b1d458a79f925376d4cf49792d3771ef192`. TASK-0043 research, TASK-0044 AST/compiler, and TASK-0045 natural-language proposal compiler are complete; TASK-0046 is active. PHASE-09 remains planned.

> Canonical progress comes from [`.ai/state/CURRENT-STATE.yaml`](.ai/state/CURRENT-STATE.yaml) and [`.ai/roadmap/ROADMAP.yaml`](.ai/roadmap/ROADMAP.yaml). README is the required human-readable mirror when the canonical progress marker changes; evidence-only state updates do not force dashboard churn.

**Overall roadmap progress: 53.75%**<br />
**Current phase: PHASE-08 — 55.00%**<br />
**Last completed task: TASK-0044 — Implement canonical segment definition AST and deterministic query compiler**<br />
**Current milestone: PHASE-08-TASK-0046-AUDIENCE-PREVIEW-UX — IN_PROGRESS**

```text
Overall  [██████████░░░░░░░░░░] 53.75%
Phase 08 [███████████░░░░░░░░░] 55.00%
```

The deterministic roadmap percentage is calculated from completed task weights. PHASE-07 is certified by TASK-0041 final acceptance / PR #405. TASK-0042 remains an unmaterialized ID gap; PHASE-08 starts at TASK-0043; TASK-0043 research, TASK-0044 AST/compiler, and TASK-0045 structured proposal are complete; TASK-0046 audience preview and UX is active.

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
| PHASE-08 | 5% | Segmentation, deterministic AST/compiler, AI proposal and preview UX | 🔄 In progress | 55.00% |
| PHASE-09 | 7% | Journeys, automation runtime, triggers/waits/branches/replay | ⏳ Planned | 0% |
| PHASE-10 | 8% | AI gateway, memory/context, typed tools, agents, red-team | ⏳ Planned | 0% |
| PHASE-11 | 5% | Experiments, variants, statistical guardrails, adaptive optimization | ⏳ Planned | 0% |
| PHASE-12 | 6% | Analytics, funnels, cohorts, Attribution, revenue/LTV, data quality | ⏳ Planned | 0% |
| PHASE-13 | 5% | Omnichannel Connectors, social Publishing, Community, listening | ⏳ Planned | 0% |
| PHASE-14 | 5% | Connector Factory, generated adapter candidates, sandbox/security gates | ⏳ Planned | 0% |
| PHASE-15 | 4% | Bounded autonomous marketing loops, budgets, kill switch, canaries | ⏳ Planned | 0% |
| PHASE-16 | 4% | Enterprise identity/governance, Billing, white-label, residency, DR | ⏳ Planned | 0% |

### PHASE-07 closeout evidence (historical)

PR #402 exact head `34b1a0a2632218cd87ae070547f16a20dc76ba5a` passed AI Continuity Guard, Application Foundation CI, Security Supply Chain CI and all applicable E2E/integration checks before merging as `ed7644bddabfe9eea4128a3c607e9cb2c9d1a20e`; it staged the exclusive TASK-0041 Operator UX lane.

PR #403 merged exact worker head `391f43b3e7ee720be878294009a5993c163da685` into `ship/week-1` as `7f43eda18cc95ab90ea0b56207e67476e7433fab`. Its exact worker Shipping Fast Gate passed. Product-bearing integration Application Foundation CI passed backend tests, infrastructure integration, architecture checks, PHP static analysis/formatting, frontend typecheck/unit tests/build, PHP 8.3 compatibility, and Playwright smoke.

The integration push correctly exposed a missing global continuity-ledger handoff. PR #404 synchronized PR #402's current Lane-4 authority, appended the hash-chained checkpoint, and passed its exact Shipping Fast Gate. Resulting integration head `0733c40eead4038e2c1d7f19a50df23b88aaac6a` passed Continuity, Application Foundation and Shipping Fast Gate. Product/test files are unchanged from the fully tested `7f43eda` snapshot.

TASK-0041 AC-1..AC-8 were accepted by PR #405. Its exact head passed Continuity, Application Foundation and Security Supply Chain before merging as `87e65b1d458a79f925376d4cf49792d3771ef192`; resulting-main gates passed and were reconciled at `6d0269bfe9b44b0623fbe1eb0e4d59fd1462e115`.

The stale preplanned TASK-0042 reservation remains an unmaterialized identifier gap because PR #405 completed PHASE-07 certification. TASK-0043 begins PHASE-08; TASK-0043 research, TASK-0044 AST/compiler, and TASK-0045 proposal compiler are complete; TASK-0046 is active, and PHASE-09 remains inactive.

### Current execution snapshot

PHASE-08 research and TASK-0042 plan-drift reconciliation are recorded in `.ai/research/PHASE-08/TASK-0043-RESEARCH.md`. TASK-0044 AST/compiler is complete. TASK-0045 natural-language proposal compiler is complete; TASK-0046 preview/count and audience UX is active while TASK-0047 remains planned. PHASE-08 progress is 55.00%; deterministic roadmap progress is 53.75%. No Runner task or PHASE-09 work is active.

### README progress-sync contract

README synchronization is marker-driven. When `.ai/state/CURRENT-STATE.yaml` changes roadmap/phase percentage, current phase, active task, current milestone, or milestone status, the same PR must update this README. Evidence-only changes such as exact-head run IDs, quality evidence, snapshot-basis SHA, queue/Runner evidence, or other non-marker metadata do not require README churn. `tools/supervisor_contract.py` validates both the canonical marker and PR-level marker-change rule.

## Delivery estimate assumptions

Delivery timing depends on exact-head CI, production-representative recovery/reconciliation evidence, provider policies, security gates, accessibility, AI evaluation/red-team work, and enterprise recovery/compliance requirements. The roadmap is research-first, so estimates should be recalculated after each phase certification rather than treated as fixed deadlines.

## For coding agents and contributors

Agent instruction revision: `parallel-v2.7.0-wave-acceleration`  
Agent instruction fingerprint: `e1915e44d40288e0e8071860deb501ef6e808b3a393cf41fb850dc94cf8bce58`

**URL-only repository entry:** A message containing only this repository's GitHub URL is read-only: reconcile current repo state and show shuffled numbered next actions; do not mutate until a later numeric selection is revalidated.

**Interactive next-action handoff:** Every development response exposes 1-3 repository-valid next actions. When two or more options exist, their visible 1/2/3 numbers are reshuffled each handoff; if the previously selected action/number is known, that action must move to a different number next time. The canonical action is marked Recommended instead of being fixed to option 1. When the chat host supports clickable action controls, selecting one submits its exact request to start the next turn; the Supervisor still revalidates compact state, exact main, Issues/PRs, coordination and Runner evidence before acting. If buttons are unavailable, the same shuffled actions are shown as numbered one-line commands that can be sent unchanged. A selection never bypasses exact-head CI, security, merge authority, deferred Runner rules, or the Fast Batch Development scope boundary.

VSN uses a **Supervisor-controlled multi-agent workflow**. The agent operating the main-repository context is the Supervisor; protected `main` is not a scratch branch. Worker and Supervisor implementation happens on pre-created dedicated branches/worktrees listed in [`.ai/parallel/AI-NATIVE-PLAN.md`](.ai/parallel/AI-NATIVE-PLAN.md).

**Week-1 Shipping Mode is active.** Sprint feature/workstream PRs use `ship/week-1` as the integration target, `ai_parallel.py sync-check` validates that shipping baseline for workers, and PRs must pass `Shipping Fast Gate`. Independent leased lanes can keep coding from the last green integration baseline while a sibling merge is still verifying, but must sync the latest required green integration head before submission/merge/dependency consumption. Work is promoted to `main` only from a green integration baseline. Full protected-main application, security and governance gates remain mandatory. The activation-time `TASK-0026` workstreams are grandfathered as a drain wave: existing occupied slots may finish, but no new writable slot may be added or reassigned above the five-writer shipping cap; the cap becomes hard after TASK-0026 transitions. See [`.ai/parallel/WEEK-1-SHIPPING-PLAN.md`](.ai/parallel/WEEK-1-SHIPPING-PLAN.md).

**Strict plan-following, change-aware CI, and Development Acceleration v2.7 are mandatory.** Work is wave-oriented: batch dependency-ready disjoint leases into one control carrier when distinct real agents are available; independent leased lanes may code from the last green shipping baseline while a newer sibling integration head verifies; synchronize latest required green `ship/week-1` before submission/merge/dependency consumption; worker PRs use Shipping Fast Gate; terminal worker evidence rides into the next substantial wave-control/promotion PR; full protected-main Application + Security gates remain concentrated at promotion/final-acceptance/security boundaries. Per-lane protected-main orchestration PRs are not the default. No acceleration may fake agents, overlap write paths, consume pending/failed integration, or weaken permissions/security. Pure `.ai/**`, `docs/**`, `README.md`, and `AGENTS.md` diffs default to lightweight control CI; unknown/non-control paths fail closed to full Application + Security CI. Add the exact standalone PR line `CI-Mode: full` whenever a control-only certification/release/security milestone still requires full gates. Runner optimization tasks remain deferred in the persistent benchmark backlog and are not executed opportunistically.

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