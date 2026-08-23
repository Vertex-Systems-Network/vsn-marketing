# PHASE-00 — Constitution & Specification

## Goal

Create a repository that any authorized developer or AI agent can enter cold, determine the exact project state, execute only the intended work, validate progress, checkpoint safely, and resume after interruption.

## Exit gate

- Agent operating contract exists.
- Project charter, architecture, module registry, integration rules, security/testing/DoD exist.
- Master roadmap is locked.
- Machine-readable current state and task registry exist.
- Validator/CLI exists and passes.
- CI validates continuity state and implementation/state drift.
- Initial technical stack is decided by approved ADR.
- Initial application skeleton can be bootstrapped only after stack decision.

## Tasks

- TASK-0001 — Install AI continuity control plane.
- TASK-0002 — Verify and lock initial application stack/boot architecture via ADR.

Additional Phase-00 tasks may be added only if they are required to satisfy the exit gate; registry/state must be updated in the same change.
