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
8. New systems are researched against current authoritative internet/official sources before implementation.
9. Research extends/reconciles the preplanned roadmap before code; it does not silently rewrite history or acceptance criteria.
10. Cross-cutting security, privacy, reliability, performance, accessibility, supply-chain, UI/UX, data-quality, and AI-safety gates are planned requirements.

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
- `10-AI-CONTROL-PLANE.md` — product AI runtime, tools, risk, memory, eval and autonomy boundaries.
- `11-RESEARCH-FIRST-STANDARD.md` — mandatory current research/evidence/reconciliation protocol before new systems.
- `12-QUALITY-ENGINEERING-GATES.md` — cross-cutting audit-derived quality and production-readiness gates.
- `roadmap/` — phase sequence, measurable phase gates, and the long-horizon preplanned TASK-0013..TASK-0100 skeleton.
- `tasks/` — machine-readable execution tasks and registry.
- `state/` — current state, checkpoint, blockers, test state and append-only execution journal.
- `adr/` — canonical architecture decision records.
- `decisions/` — other governed decision records where applicable.
- `contracts/` — provider, AI and event contracts.
- `integrations/` — provider catalog/capabilities.
- `ai/` — machine-readable agent, tool, prompt, model, autonomy, memory and evaluation registries.
- `research/` — dated task/phase research evidence packs once created by active research-gated tasks.

Run `python tools/ai_state.py status` for the current handoff.
