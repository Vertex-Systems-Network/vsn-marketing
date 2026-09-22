# VSN Marketing

AI-native, provider-agnostic marketing operating system under active development.

## Development progress

<!-- AI_PROGRESS_SNAPSHOT roadmap=45.91 phase=27.27 current_phase=PHASE-07 active_task=TASK-0038 milestone=TASK-0039-REGISTRATION status=COMPLETE -->

> Last verified protected-main registration baseline: **2026-09-22** at `3dbd2aca8547167b42610007e8494606a9f9d7df` after TASK-0039 planned-registration PR #351 merged. Exact source `918e1d1d8a290d9b9c729a1764e7fd8160c5d80a` passed AI Continuity Guard `35670787818`, Application Foundation CI `35670787786`, and Security Supply Chain CI `35670787729`. TASK-0038 remains active/ready and TASK-0039 remains planned-only until the separate guarded transition.
>
> Canonical progress comes from [`.ai/state/CURRENT-STATE.yaml`](.ai/state/CURRENT-STATE.yaml) and [`.ai/roadmap/ROADMAP.yaml`](.ai/roadmap/ROADMAP.yaml). README is the required human-readable mirror for every durable milestone state change.

**Overall roadmap progress: 45.91%**  
**Current phase: PHASE-07 — 27.27%**  
**Active task: TASK-0038 — Implement campaign lifecycle, immutable snapshots, recipients/targets, approvals, and audit history**  
**Last completed task: TASK-0037 — Research current campaign/social publishing APIs, app-review/scopes, scheduling constraints, media rules, and market calendar workflows**  
**Current milestone: TASK-0039-REGISTRATION — COMPLETE**

```text
Overall  [█████████░░░░░░░░░░░] 45.91%
Phase 07 [█████░░░░░░░░░░░░░░░] 27.27%
```

The deterministic roadmap percentage advances from completed task weights, so partial TASK-0038 milestones can land substantial product code while the canonical roadmap percentage remains 47%. The README still updates on every durable milestone state change so the active milestone, evidence, task and phase never remain stale.

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
| **PHASE-07** | **7%** | **Campaigns, Publishing, approvals, scheduling, unified calendar** | 🚧 **In progress — TASK-0038 accepted / TASK-0039 registration** | **27.27%** |
| PHASE-08 | 5% | Segments, deterministic query compiler, NL-to-segment compiler | ⏳ Planned | 0% |
| PHASE-09 | 7% | Journeys, automation runtime, triggers/waits/branches/replay | ⏳ Planned | 0% |
| PHASE-10 | 8% | AI gateway, memory/context, typed tools, agents, red-team | ⏳ Planned | 0% |
| PHASE-11 | 5% | Experiments, variants, statistical guardrails, adaptive optimization | ⏳ Planned | 0% |
| PHASE-12 | 6% | Analytics, funnels, cohorts, Attribution, revenue/LTV, data quality | ⏳ Planned | 0% |
| PHASE-13 | 5% | Omnichannel Connectors, social Publishing, Community, listening | ⏳ Planned | 0% |
| PHASE-14 | 5% | Connector Factory, generated adapter candidates, sandbox/security gates | ⏳ Planned | 0% |
| PHASE-15 | 4% | Bounded autonomous marketing loops, budgets, kill switch, canaries | ⏳ Planned | 0% |
| PHASE-16 | 4% | Enterprise identity/governance, Billing, white-label, residency, DR | ⏳ Planned | 0% |

### Current execution snapshot

TASK-0038 is active in PHASE-07. Campaign persistence/domain foundation is trusted on main through PR #342, lifecycle/approval orchestration through PR #344, and immutable revision/history hardening through PR #347.

The canonical implementation now includes workspace-scoped campaign lifecycle persistence, immutable snapshots and canonical targets, snapshot-bound approval history, optimistic concurrency/idempotency, fail-closed workspace isolation, authorized approval/rejection/revocation/ready orchestration, approver permission/role re-evaluation, stale/future provider-capability rejection, material-revision invalidation, append-only stale-approval revocation, governed cancellation/completion provenance, immutable previous/new revision lineage, and replay-safe material revision commands.

TASK-0038 final acceptance PR #350 is trusted and AC-1 through AC-8 remain true. TASK-0038 intentionally remains active/ready during the separate planned-only TASK-0039 registration PR #351. TASK-0039 implementation, live provider publication, provider media upload, provider credential activation and deployment/release authority remain inactive.

PR #349 is merged on protected main and closes the scheduled-intent/canonical-target evidence gap: authorized Approved -> ScheduledIntent state is recorded without scheduler/provider execution, current exact-snapshot approval is revalidated for fresh scheduling, historical duplicate replay remains idempotent, fixed-instant intent is immutable evidence, and ContactIdentity/List/Tag targets are reproducible and workspace-isolated with exact materialized list/tag membership. PR #350 is the Supervisor-only final TASK-0038 acceptance surface.

README progress-sync protocol v2.4.2 remains enforced. TASK-0039 planned registration is now trusted and terminally reconciled; deterministic progress remains roadmap 45.91% / PHASE-07 27.27% while TASK-0038 stays ready. The next separate guarded transition will complete TASK-0038 and activate TASK-0039 ready.

### README progress-sync contract

Every durable milestone PR that changes `.ai/state/CURRENT-STATE.yaml` must update this README in the same PR. `tools/supervisor_contract.py` validates the machine progress marker against canonical state and rejects a durable state-changing PR that omits README. CI/status-only interactions without repository state mutation do not fabricate README commits.

## Delivery estimate assumptions

Delivery timing depends on exact-head CI, production-representative recovery/reconciliation evidence, provider policies, security gates, accessibility, AI evaluation/red-team work, and enterprise recovery/compliance requirements. The roadmap is research-first, so estimates should be recalculated after each phase certification rather than treated as fixed deadlines.

## For coding agents and contributors

Agent instruction revision: `parallel-v2.4.2-readme-progress-sync`  
Agent instruction fingerprint: `4918ea8be1f1ea1d2e1b7b439c5cd35d04c06daa7200602bb5245149a19afa38`

VSN uses a **Supervisor-controlled multi-agent workflow**. The agent operating the main-repository context is the Supervisor; protected `main` is not a scratch branch. Worker and Supervisor implementation happens on pre-created dedicated branches/worktrees listed in [`.ai/parallel/AI-NATIVE-PLAN.md`](.ai/parallel/AI-NATIVE-PLAN.md).

**Week-1 Shipping Mode is active.** Sprint feature/workstream PRs use `ship/week-1` as the integration target, must pass `Shipping Fast Gate`, and are promoted to `main` only from a green integration baseline. Full protected-main application, security and governance gates remain mandatory. The activation-time `TASK-0026` workstreams are grandfathered as a drain wave: existing occupied slots may finish, but no new writable slot may be added or reassigned above the five-writer shipping cap; the cap becomes hard after TASK-0026 transitions. See [`.ai/parallel/WEEK-1-SHIPPING-PLAN.md`](.ai/parallel/WEEK-1-SHIPPING-PLAN.md).

**Strict plan-following and change-aware CI are mandatory.** Every agent follows recover/validate -> canonical state/task/plan -> exact head -> change classification -> one logical milestone -> class-appropriate checks -> exact-head PR gates -> merge -> repository re-read -> separate successor registration/transition. Pure `.ai/**`, `docs/**`, `README.md`, and `AGENTS.md` diffs default to lightweight control CI; unknown/non-control paths fail closed to full Application + Security CI. Add the exact standalone PR line `CI-Mode: full` whenever a control-only certification/release/security milestone still requires full gates. Runner optimization tasks remain deferred in the persistent benchmark backlog and are not executed opportunistically.

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