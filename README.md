# VSN Marketing

AI-native, provider-agnostic marketing operating system under active development.

## Development progress

> Last verified: **2026-09-01** against `main` at `ed34452dd8c11dd0f2472fae72020a8b479fe289`.
>
> Canonical progress comes from [`.ai/state/CURRENT-STATE.yaml`](.ai/state/CURRENT-STATE.yaml) and [`.ai/roadmap/ROADMAP.yaml`](.ai/roadmap/ROADMAP.yaml). ETA ranges below are planning estimates, not completion guarantees.

**Overall roadmap progress: 21.5%**  
**Current phase: PHASE-03 — 50%**  
**Active task: TASK-0016**  
**Last completed task: TASK-0015**  
**Estimated remaining effort: ~66–106 focused development days**

```text
Overall  [████░░░░░░░░░░░░░░░░] 21.5%
Phase 03 [██████████░░░░░░░░░░] 50.0%
```

```mermaid
pie showData
    title VSN Marketing Roadmap Completion
    "Completed / certified weight" : 21.5
    "Remaining roadmap weight" : 78.5
```

### Phase / module progress

| Phase | Weight | Main modules / capability | Status | Progress | Estimated remaining days |
|---|---:|---|---|---:|---:|
| PHASE-00 | 4% | Architecture, AI continuity, project governance | ✅ Complete | 100% | 0 |
| PHASE-01 | 7% | Core, Identity, Tenancy, RBAC, Audit, Security foundation, queues/runtime | ✅ Complete | 100% | 0 |
| PHASE-02 | 7% | Contacts, identities, companies, lists/tags, Consent, Events | ✅ Complete | 100% | 0 |
| **PHASE-03** | **7%** | **Providers, Connectors, Webhooks, Integrations, provider security baseline** | 🚧 **In progress** | **50%** | **2–4** |
| PHASE-04 | 7% | Delivery, routing, throttling, idempotency, retry/failover, SLOs | ⏳ Planned | 0% | 4–6 |
| PHASE-05 | 6% | Domains, sender identity, Suppressions, Deliverability | ⏳ Planned | 0% | 4–6 |
| PHASE-06 | 6% | Templates, Content, Assets, creative/editor pipeline | ⏳ Planned | 0% | 5–8 |
| PHASE-07 | 7% | Campaigns, Publishing, approvals, scheduling, unified calendar | ⏳ Planned | 0% | 5–8 |
| PHASE-08 | 5% | Segments, deterministic query compiler, NL-to-segment compiler | ⏳ Planned | 0% | 3–5 |
| PHASE-09 | 7% | Journeys, automation runtime, triggers/waits/branches/replay | ⏳ Planned | 0% | 5–8 |
| PHASE-10 | 8% | AI gateway, memory/context, typed tools, agents, red-team | ⏳ Planned | 0% | 7–11 |
| PHASE-11 | 5% | Experiments, variants, statistical guardrails, adaptive optimization | ⏳ Planned | 0% | 4–6 |
| PHASE-12 | 6% | Analytics, funnels, cohorts, Attribution, revenue/LTV, data quality | ⏳ Planned | 0% | 5–8 |
| PHASE-13 | 5% | Omnichannel Connectors, social Publishing, Community, listening | ⏳ Planned | 0% | 6–10 |
| PHASE-14 | 5% | Connector Factory, generated adapter candidates, sandbox/security gates | ⏳ Planned | 0% | 5–8 |
| PHASE-15 | 4% | Bounded autonomous marketing loops, budgets, kill switch, canaries | ⏳ Planned | 0% | 5–8 |
| PHASE-16 | 4% | Enterprise identity/governance, Billing, white-label, residency, DR | ⏳ Planned | 0% | 6–10 |

### Current execution snapshot

PHASE-03 establishes the provider/channel abstraction and initial adapters without allowing provider-specific behavior to own canonical product data. Its task chain is:

| Task | Phase weight | Purpose | State |
|---|---:|---|---|
| TASK-0013 | 15% | Provider/channel research and architecture reconciliation | ✅ Complete |
| TASK-0014 | 15% | Repository security and software-supply-chain hardening | ✅ Complete |
| TASK-0015 | 20% | Canonical provider capability/connection/quota foundation | ✅ Complete |
| **TASK-0016** | **20%** | **Adapter/error/quota/webhook/reconciliation contracts** | 🚧 **Active** |
| TASK-0017 | 20% | Initial reference connectors and sandbox contract matrix | ⏳ Next |
| TASK-0018 | 10% | PHASE-03 certification | ⏳ Planned |

The current canonical calculation is:

```text
PHASE-00  4.0 / 4.0
PHASE-01  7.0 / 7.0
PHASE-02  7.0 / 7.0
PHASE-03  3.5 / 7.0
-------------------
TOTAL    21.5 / 100
```

## Delivery estimate assumptions

The **66–106 focused development day** range assumes continuous AI-native implementation, fast review/merge cycles, stable infrastructure, and no major external API/app-review blockers. Later phases carry higher uncertainty because they depend on provider policies, production-scale performance evidence, security gates, accessibility, AI evaluation/red-team work, and enterprise recovery/compliance requirements.

The roadmap is research-first: new provider/API/model realities can add justified tasks, so the estimate should be recalculated after each phase certification rather than treated as a fixed deadline.

## For coding agents and contributors

Agent instruction revision: `parallel-v2.1-supervisor-onboarding`  
Agent instruction fingerprint: `d2f26c4a767db4bfda4e89b38eac2ea1958c160a26327a1715d88ed7452cdc75`

VSN uses a **Supervisor-controlled multi-agent workflow**. The agent operating the main-repository context is the Supervisor; protected `main` is not a scratch branch. Worker and Supervisor implementation happens on pre-created dedicated branches/worktrees listed in [`.ai/parallel/AI-NATIVE-PLAN.md`](.ai/parallel/AI-NATIVE-PLAN.md).

Before modifying the repository, read this README, [`AGENTS.md`](AGENTS.md), [`.ai/13-PARALLEL-DEVELOPMENT.md`](.ai/13-PARALLEL-DEVELOPMENT.md), and the machine registries under [`.ai/parallel/`](.ai/parallel/). Then run the full startup sequence from `AGENTS.md`, including:

```bash
python tools/ai_txn.py recover
python tools/ai_txn.py validate
python tools/ai_state.py recover
python tools/ai_state.py validate
python tools/ai_journal.py validate
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
- **Supervisor interrupt:** a submitted workstream PR preempts optional Supervisor module work. The Supervisor pauses, reviews, merges only approved/current-main/green changes, synchronizes its own branch, then resumes.
- **Merge alert:** after every workstream merge the Supervisor posts this exact alert to GitHub issue [#43](https://github.com/Vertex-Systems-Network/vsn-marketing/issues/43) and every other open registered workstream PR: **`New changes have been merged — please merge these changes into your branch first, then resume your own work.`**
- **Resume only after sync:** every alerted agent must merge/pull latest `main`, pass `python tools/ai_parallel.py sync-check`, rerun affected fast checks, and only then resume.

The TASK-0017 pilot branches were pre-created from trusted main `bc821953b69dea2ac58eb1e3dbe41699a0dc111b` and remain staged until TASK-0017 is canonical: `agent/task-0017-research-qa`, `agent/task-0017-ses`, `agent/task-0017-brevo`, `agent/task-0017-gmail`, `agent/task-0017-contract-matrix`, and `supervisor/task-0017-integration`.

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
