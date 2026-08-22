# VSN Marketing

AI-native, provider-agnostic marketing operating system under active design and development.

## Current repository state

The product code has not been scaffolded yet. Phase 00 is establishing the architecture and continuity controls first so any authorized human or AI coding agent can resume deterministically without depending on conversation history.

## For coding agents and contributors

Read [`AGENTS.md`](AGENTS.md), then run:

```bash
python tools/ai_state.py validate
python tools/ai_state.py status
```

The active task, exact next action, progress, blockers, tests, roadmap, architecture rules, and last checkpoint live under [`.ai/`](.ai/).

## Architectural direction

- Modular monolith first; event-driven boundaries.
- Provider-neutral core with SMTP/API/mailbox/marketing adapters.
- Canonical customer, consent, event, message, template, campaign and journey models.
- Deterministic consent/suppression/security/approval gates.
- Specialized AI agents behind typed tools and structured outputs.
- Future AI Connector Factory for controlled provider integration generation.

Implementation sequence is defined in `.ai/roadmap/MASTER-ROADMAP.md`.
