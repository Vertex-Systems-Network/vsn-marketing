# AI Governance Rules

## Two AI planes

### Development AI
AI coding agents working on the repository follow `AGENTS.md`, the active task ledger, ADRs, contracts, tests, and checkpoints.

### Product AI
AI features inside VSN Marketing must operate through typed tools and structured outputs. Product AI never receives unrestricted authority over data, delivery, credentials, billing, or infrastructure.

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
- rely on previous chat as project state.

AI MUST:

- validate state at session start and end;
- inspect the active task and last checkpoint;
- preserve exact next-action handoff on interruption;
- record blockers rather than guessing;
- keep implementation, tests, task state, registry, and checkpoint synchronized.

## Product AI risk tiers

- Low risk: drafting, analysis, suggestions, read-only insights.
- Medium risk: bounded optimization within explicit workspace policy.
- High risk: bulk send approval, new sending identity/domain, large volume change, provider credentials, billing commitment, destructive data operations, policy changes, generated connector activation. High risk requires deterministic approval gates.

## Structured output rule

Execution-driving AI decisions use schemas/enums and confidence/reason codes, not free-form prose. Invalid schema output is rejected, not guessed.

## Model portability

No core workflow may depend on a single AI vendor. Model/provider selection is behind an AI gateway with capability metadata, budgets, structured-output validation, observability, and fallback policy.
