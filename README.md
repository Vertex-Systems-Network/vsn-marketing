# VSN Marketing

AI-native, provider-agnostic marketing operating system under active development.

## Development progress

> Last verified: **2026-09-09** from trusted `main` at `775f8a47cfa4fe092bc90346788f37567f7e3e36` after TASK-0022 activation PR #91 merged and post-merge AI Continuity, Application Foundation, Security Supply Chain, Release Integrity, and OpenSSF Scorecard workflows passed.
>
> Canonical progress comes from [`.ai/state/CURRENT-STATE.yaml`](.ai/state/CURRENT-STATE.yaml) and [`.ai/roadmap/ROADMAP.yaml`](.ai/roadmap/ROADMAP.yaml). The README is a human-readable snapshot; canonical task acceptance remains in `.ai/`.

**Overall roadmap progress: 30.13%**  
**Current phase: PHASE-04 — 73.33%**  
**Active task: TASK-0022**  
**Last completed task: TASK-0021**  
**Parallel execution: 5 registered TASK-0022 worker lanes plus the reserved Supervisor integration branch; worker implementation starts only after this control-plane activation is accepted on main**

```text
Overall  [██████░░░░░░░░░░░░░░] 30.13%
Phase 04 [███████████████░░░░░] 73.33%
```

```mermaid
pie showData
    title VSN Marketing Roadmap Completion
    "Completed / certified weight" : 30.13
    "Remaining roadmap weight" : 69.87
```

### Phase / module progress

| Phase | Weight | Main modules / capability | Status | Progress |
|---|---:|---|---|---:|
| PHASE-00 | 4% | Architecture, AI continuity, project governance | ✅ Complete | 100% |
| PHASE-01 | 7% | Core, Identity, Tenancy, RBAC, Audit, Security foundation, queues/runtime | ✅ Complete | 100% |
| PHASE-02 | 7% | Contacts, identities, companies, lists/tags, Consent, Events | ✅ Complete | 100% |
| PHASE-03 | 7% | Providers, Connectors, Webhooks, Integrations, provider security baseline | ✅ Complete | 100% |
| **PHASE-04** | **7%** | **Delivery, routing, throttling, idempotency, retry/failover, SLOs** | 🚧 **In progress** | **73.33%** |
| PHASE-05 | 6% | Domains, sender identity, Suppressions, Deliverability | ⏳ Planned | 0% |
| PHASE-06 | 6% | Templates, Content, Assets, creative/editor pipeline | ⏳ Planned | 0% |
| PHASE-07 | 7% | Campaigns, Publishing, approvals, scheduling, unified calendar | ⏳ Planned | 0% |
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

PHASE-04 is active. `TASK-0019` delivery-engine research, `TASK-0020` immutable execution snapshots, and `TASK-0021` queue routing/idempotency/quota/backpressure are complete. `TASK-0022` is now the only executable roadmap task.

TASK-0022 implements provider-neutral delivery recovery semantics over the accepted TASK-0021 operation/admission foundation. The bounded scope is: normalized retry classification, tenant-scoped circuit breakers, dead-letter handling, idempotent ambiguity reconciliation, and compatible provider failover. The safety boundary is strict: an ambiguous provider outcome must enter reconciliation before replay/failover; an accepted logical operation never reroutes; provider names must not become core policy branches.

The parallel cycle is decomposed into five conflict-safe worker lanes created from trusted main `775f8a47cfa4fe092bc90346788f37567f7e3e36` before any cycle planning mutation:

- `WS-0022-RETRY-CLASSIFICATION` — deterministic mapping from normalized provider error evidence to delivery recovery disposition.
- `WS-0022-CIRCUIT-BREAKER` — tenant/provider-connection/operation-class breaker state and transitions.
- `WS-0022-RECONCILIATION` — ambiguity/reconciliation decision primitives that never infer acceptance from missing evidence.
- `WS-0022-DEAD-LETTER` — deterministic dead-letter reason/evidence primitives for terminal/exhausted/expired/invariant outcomes.
- `WS-0022-FAILOVER-POLICY` — failover eligibility that permits rerouting only when prior non-acceptance is proven and forbids accepted/ambiguous reroutes.

The reserved `supervisor/task-0022-parallel-integration` branch owns shared persistence/state-machine migrations, service-provider/config wiring, orchestration, canonical `.ai/**` state, and final integration after worker deliveries are accepted. Worker write paths are disjoint and do not include Supervisor-owned shared paths.

No sender-domain/deliverability policy, secrets/credentials, paid provider execution, suppression policy, TASK-0023, TASK-0024, or later-phase behavior is authorized by TASK-0022.

Trusted-main certification for TASK-0022 activation:

- AI Continuity Guard `34394642732` — PASS
- Application Foundation CI `34394642755` — PASS
- Security Supply Chain CI `34394642810` — PASS
- Release Integrity `34394642691` — PASS
- OpenSSF Scorecard `34394642730` — PASS

Current canonical calculation:

```text
PHASE-00  4.00 / 4.00
PHASE-01  7.00 / 7.00
PHASE-02  7.00 / 7.00
PHASE-03  7.00 / 7.00
PHASE-04  5.13 / 7.00
---------------------
TOTAL    30.13 / 100
```

## Delivery estimate assumptions

Delivery timing depends on exact-head CI, production-representative recovery/reconciliation evidence, provider policies, security gates, accessibility, AI evaluation/red-team work, and enterprise recovery/compliance requirements. The roadmap is research-first, so estimates should be recalculated after each phase certification rather than treated as fixed deadlines.

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

The active TASK-0022 parallel cycle has five registered worker lanes, all pre-created from the same trusted main baseline with exclusive disjoint write paths. The reserved Supervisor integration branch owns shared integration and canonical closeout after accepted worker deliveries.

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
