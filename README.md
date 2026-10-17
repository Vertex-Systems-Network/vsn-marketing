# VSN Marketing

AI-native, provider-agnostic, permission-based marketing operating system (modular Laravel monolith). All 16 roadmap phases are complete; live providers, live payments and production activation stay disabled until the owner explicitly enables them.

## Development progress

<!-- AI_PROGRESS_SNAPSHOT roadmap=52.59 phase=32.35 current_phase=PHASE-17 active_task=TASK-0148 milestone=TASK-0147-UX status=IN_PROGRESS -->

> Canonical progress comes from [`.ai/state/CURRENT-STATE.yaml`](.ai/state/CURRENT-STATE.yaml) and [`.ai/roadmap/ROADMAP.yaml`](.ai/roadmap/ROADMAP.yaml). README is the human-readable mirror.

**Overall roadmap progress: 52.59%**<br />
**Current phase: PHASE-17 — 32.35%**<br />
**Last completed task: TASK-0100**<br />
**Current milestone: TASK-0147-UX — IN_PROGRESS**

```text
 Overall  [███████████░░░░░░░░░] 52.59%
Phase 17 [██████░░░░░░░░░░░░░░] 32.35%
```

The foundation (PHASE-00..16) is complete and now counts as 50% of the roadmap (ADR-0007). The product track PHASE-17..PHASE-24 — Next.js web and Android/iOS apps, panels (Super Admin, Buyer, Seller: reseller/agency, marketplace, affiliate), live email providers and tracking, social media, AI providers, ads, packages and reports — is active: see [`.ai/roadmap/PRODUCT-TRACK-PLAN.md`](.ai/roadmap/PRODUCT-TRACK-PLAN.md), the [AI master prompt](docs/product/AI-MASTER-PROMPT.md), [Systems Bank](.ai/research/PRODUCT-TRACK/SYSTEMS-BANK.md) and [Options Bank](.ai/research/PRODUCT-TRACK/OPTIONS-BANK.md).

### Phase / module progress

| Phase | Weight (PHASE-00..16 halved by ADR-0007) | Main modules / capability | Status | Progress |
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
| PHASE-14 | 5% | Connector Factory, generated adapter candidates, sandbox/security gates | ✅ TASK-0082–0087 bounded certification complete; production activation remains outside scope | 100% |
| PHASE-15 | 4% | Bounded autonomous marketing loops, budgets, kill switch, canaries | ✅ TASK-0093 offline phase certification complete; no production authority | 100% |
| PHASE-16 | 4% | Enterprise identity/governance, Billing, white-label, residency, DR | PRs #579, #584–#589: identity, residency, agency, Stripe test billing, DR and certification within ADR-0006 boundaries — complete | 100% |
| PHASE-17 | 8% | API layer, Next.js shell, design system, roles, Super Admin/Buyer/Seller panels | 🔄 Active (TASK-0102 research) | 0% |
| PHASE-18 | 10% | Live email providers, lists, template studio, campaigns, tracking | Planned | 0% |
| PHASE-19 | 8% | Social accounts, composer, calendar, AI posts, duplicate check | Planned | 0% |
| PHASE-20 | 5% | ChatGPT, Gemini, Claude live; AI writer | Planned | 0% |
| PHASE-21 | 5% | Ads and boosting with spend caps | Planned | 0% |
| PHASE-22 | 6% | Plans, billing, reseller/affiliate, marketplace | Planned | 0% |
| PHASE-23 | 4% | Reports, search/filters, launch QA | Planned | 0% |
| PHASE-24 | 4% | Android and iOS apps | Planned | 0% |


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

## Product overview

### Tech stack

| Layer | Technology |
|---|---|
| Backend | PHP 8.3+ (CI on 8.3 and 8.5), Laravel, modular monolith under `app/Modules/*` |
| Frontend | React 19 + TypeScript, Inertia.js v3, Tailwind CSS v4, Vite 8 |
| Data | PostgreSQL (production), SQLite (fast tests), Redis (queues/cache) |
| Tests | Pest/PHPUnit, Vitest + Testing Library, Playwright E2E, PostgreSQL/Redis integration suite |
| Quality & security | Pint, PHPStan (Larastan), CodeQL, secret scan, dependency audit, container scan, SBOM, SHA-pinned Actions |

### Modules

Core, Identity (tenancy, RBAC, SSO/SCIM, sessions, governance, agency, billing, recovery), Audit, Contacts, Consent, Events, Providers, DeliveryEngine, Templates, Content, Assets, Publishing, Segmentation, Journeys, Experiments, Analytics, AI.

### Roles and permissions

There are no hard-coded role panels. Each workspace defines its own roles and grants them permissions from a fixed catalog of 22 (`app/Modules/Identity/Domain/Authorization/PermissionCatalog.php`): contact read/write, campaign read/create/approve/send, journey create/read/publish/replay, template create/publish, provider create and credential management, AI execute/approve, analytics read, billing, audit export, identity, privacy and agency management. Agencies receive only the permissions a client explicitly delegates; billing, identity, provider credentials, privacy, audit export and re-delegation are never delegable.

### User interface screens

| Area | Screens (`resources/js/pages`) |
|---|---|
| Access | `auth/login`, `auth/workspaces` |
| Enterprise | `enterprise/overview` (capabilities, audit metadata review) |
| Operators | `journeys/operator`, `segmentation/operator`, `segmentation/rule-builder`, `publishing/operator`, `analytics/operator`, `analytics/autonomy-preview`, `ai/offline-incident` |
| Public | `welcome` |

Known UI gaps: no shared app layout/navigation, no role-management screen, and backend-only features (contacts, campaigns, templates, billing, privacy, agency branding, backup) do not have operator screens yet.

### Phase 16 enterprise controls (ADR-0006)

| Area | What exists | Boundary |
|---|---|---|
| Identity | Pinned OIDC login with PKCE, replay protection, governed subject binding, SCIM deactivation, session revocation | SSO/SCIM disabled by default |
| Residency | Eight data surfaces must be attested `eu`; legal holds, export, anonymizing erasure, retention | Unset surfaces fail closed |
| Agency | Delegated administration, sibling-client isolation, spoof-proof branding | — |
| Billing | Idempotent metering, entitlements, two-person plan changes, verified Stripe test webhooks | Live charges hard-disabled |
| Recovery | Encrypted tenant backup/restore, measured RPO 24h / RTO 4h exercise | Exercised in CI, no production target |

### Getting started

```bash
composer install && npm ci
cp .env.example .env && php artisan key:generate
php artisan migrate
php artisan serve          # backend, in one terminal
npm run dev                # Vite frontend, in another terminal
composer test && composer lint:check && composer analyse
npm run typecheck && npm run test && npm run test:e2e
```

## Architectural direction

- Modular monolith first; event-driven boundaries.
- Provider-neutral core with SMTP/API/mailbox/marketing adapters.
- Canonical customer, consent, event, message, template, campaign and journey models.
- Deterministic consent/suppression/security/approval gates.
- Specialized AI agents behind typed tools and structured outputs.
- Future AI Connector Factory for controlled provider integration generation.

Implementation sequence is defined in [`.ai/roadmap/MASTER-ROADMAP.md`](.ai/roadmap/MASTER-ROADMAP.md).

