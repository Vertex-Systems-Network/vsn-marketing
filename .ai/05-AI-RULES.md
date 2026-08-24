# AI Governance Rules

## Two AI planes

### Development AI
AI coding agents working on the repository follow `AGENTS.md`, the active task ledger, ADRs, contracts, tests, checkpoints, the Research-First Standard, the Quality Engineering Gates, and the preplanned roadmap.

### Product AI
AI features inside VSN Marketing must operate through typed tools and structured outputs. Product AI never receives unrestricted authority over data, delivery, credentials, billing, infrastructure, publication, or external accounts.

## Development AI hard rules

AI MUST NOT:

- invent modules outside the registry without ADR;
- silently change the technology stack;
- rename canonical domain concepts casually;
- skip task dependencies or work ahead of the active task;
- mark incomplete work complete;
- remove/weaken tests to force green;
- bypass provider abstractions;
- commit secrets;
- directly alter architecture after an unapproved `PROPOSED` ADR;
- rely on previous chat as project state;
- implement a new subsystem/provider/channel/API/AI capability from assumptions without the required current research;
- use research as permission to silently delete, weaken, reorder, or reinterpret the preplanned roadmap;
- select an AI/provider route solely because it is popular or marketed as best;
- treat stale API/model/platform documentation as current evidence when the task is freshness-sensitive.

AI MUST:

- validate state at session start and end;
- inspect the active task and last checkpoint;
- inspect the preplanned long-horizon roadmap before proposing future scope;
- perform the Research-First Gate before implementing applicable new systems;
- prioritize current official docs, standards, upstream release notes, security guidance, and platform policies;
- create/revalidate a dated research evidence pack with source provenance and implementation impact;
- reconcile research findings into task/acceptance/ADR/roadmap state before writing newly discovered implementation;
- evaluate applicable Quality Engineering Gates for every task and phase certification;
- preserve exact next-action handoff on interruption;
- record blockers rather than guessing;
- keep implementation, tests, task state, registry, research evidence, and checkpoint synchronized.

## Research and roadmap rule

The repository maintains a preplanned minimum future implementation skeleton. Research exists to verify current external reality immediately before implementation.

A research finding may add prerequisites, acceptance criteria, new tasks, connector capability requirements, or a proposed ADR. It may not erase difficult requirements or bypass completed dependencies. If the current external reality contradicts the plan, enter reconciliation and update the plan explicitly before implementation.

If authoritative current research is materially required and unavailable, fail closed by recording a blocker rather than inventing behavior.

## Product AI risk tiers

- Low risk: drafting, analysis, suggestions, read-only insights.
- Medium risk: bounded optimization within explicit workspace policy.
- High risk: bulk send/publication approval, new sending identity/domain/account connection, large volume change, provider credentials, billing commitment, destructive data operations, policy changes, generated connector activation. High risk requires deterministic approval gates.

## Structured output rule

Execution-driving AI decisions use schemas/enums and confidence/reason codes, not free-form prose. Invalid schema output is rejected, not guessed.

Research-driving AI outputs must preserve sources/provenance, access/freshness metadata, conflicting evidence, and the reason a finding changes or confirms the plan.

## Model portability

No core workflow may depend on a single AI vendor. Model/provider selection is behind an AI gateway with capability metadata, privacy/region constraints, budgets, structured-output validation, observability, evaluation scores, and fallback policy.

Model fallback may change provider/model but may never weaken schemas, permissions, risk tier, privacy constraints, tool policy, approvals, or acceptance thresholds.

## AI safety and promotion

Prompt/model/tool/policy changes that can influence execution must be versioned, evaluated, observable, reversible, and auditable. Production promotion is based on externally computed evaluation thresholds and deterministic policy; an agent cannot approve its own governing changes.
