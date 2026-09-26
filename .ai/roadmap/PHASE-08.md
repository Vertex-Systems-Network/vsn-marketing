# PHASE-08 — Segmentation and Natural-Language Segment Compiler

Status: **IN PROGRESS — TASK-0043 research and TASK-0044 AST/compiler are complete; TASK-0045 proposal compiler is active.**

## Purpose

Build deterministic, explainable, workspace-safe audience definitions over VSN's canonical contact and event data. AI may propose a structured definition. Only a deterministic registry, policy validator and compiler may produce an executable query plan.

## Current progress

- PHASE-08: 35.00%
- Roadmap: 52.75%
- Completed: TASK-0043 research and TASK-0044 canonical AST/compiler
- Active: TASK-0045 natural-language proposal compiler
- TASK-0042: intentionally unmaterialized gap; PHASE-07 certification was accepted through TASK-0041 final acceptance / PR #405.

## Trust boundary

```
Intent -> structured proposal -> schema validation -> authorization/policy validation -> bounded deterministic compiler -> safe query plan -> preview/evaluation
```

Natural-language text, model output and client data never become SQL authority. Workspace scope comes from authenticated TenantContext, independently of the segment AST. Consent, suppression, permissions and send-time eligibility remain separate canonical gates.

## Planned sequence

1. TASK-0043 — Research segmentation/privacy/query patterns and benchmark audience builders. **Complete.**
2. TASK-0044 — Implement canonical segment definition AST and deterministic query compiler. **Complete.**
3. TASK-0045 — Implement natural-language-to-segment structured compiler. **Active.**
4. TASK-0046 — Implement preview/count/freshness/cost guards and audience UX.
5. TASK-0047 — Certify PHASE-08.

## Invariants

- Definitions are typed, versioned, canonical and explainable.
- Fields, event types, relations, operators and query functions are server-registered; unknown semantics fail closed.
- No model-generated or user-authored SQL, dynamic identifiers, arbitrary joins or raw expressions.
- Tenant scope is independently injected into every base query and relation.
- Query depth, predicate count, event window, preview size, count work, timeout and export are bounded.
- AI can only return a proposal. Ambiguity or unsupported criteria require a human correction/confirmation.
- Sensitive/inferred attributes are disabled by default; previews do not return PII by default.
- Consent and suppression are checked by the canonical send path, not inferred from saved segment membership.
- PHASE-09 journeys stay inactive until a separately researched and registered milestone.

## Research

- Evidence pack: [TASK-0043 research](../research/PHASE-08/TASK-0043-RESEARCH.md).
- Plan drift: TASK-0042 remains a documented gap, not a task to materialize.

