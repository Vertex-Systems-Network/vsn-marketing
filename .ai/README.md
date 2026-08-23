# AI Control Plane

This directory makes VSN Marketing resumable across AI models, tools, sessions, machines, and developers.

## Design principles

1. Repository state, not conversation memory, is authoritative.
2. Exactly one active execution task exists at a time.
3. Every task has dependencies, acceptance criteria, test expectations, and a deterministic status.
4. Architecture changes are explicit ADRs.
5. A checkpoint records the exact handoff point.
6. Machine validation blocks inconsistent state from merging.
7. Product AI and developer AI both operate under deterministic safety/policy boundaries.

## Directory map

- `00-PROJECT-CHARTER.md` — purpose, scope, invariants.
- `01-MASTER-ARCHITECTURE.md` — target system architecture.
- `02-MODULE-REGISTRY.md` — canonical module boundaries.
- `03-DATA-MODEL.md` — canonical domain model principles.
- `04-INTEGRATION-STANDARD.md` — provider/connector rules.
- `05-AI-RULES.md` — development-agent and product-agent governance.
- `06-SECURITY-RULES.md` — security invariants.
- `07-CODING-STANDARDS.md` — implementation standards.
- `08-TESTING-STANDARDS.md` — required verification layers.
- `09-DEFINITION-OF-DONE.md` — universal completion gate.
- `roadmap/` — phase sequence and gates.
- `tasks/` — machine-readable execution tasks and registry.
- `state/` — current state, checkpoint, blockers, test state.
- `decisions/` — architecture decision records.
- `contracts/` — provider and event contracts.
- `integrations/` — provider catalog/capabilities.

Run `python tools/ai_state.py status` for the current handoff.
