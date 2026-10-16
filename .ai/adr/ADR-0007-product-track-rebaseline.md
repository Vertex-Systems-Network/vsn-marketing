# ADR-0007 — Product track PHASE-17..23 and roadmap rebaseline

Status: **PROPOSED**
Date: 2026-10-17

## Context

The PHASE-00..16 baseline is complete (roadmap 100%), but the owner's product requirements R1–R20 (`.ai/roadmap/PRODUCT-TRACK-PLAN.md`) — live email/social/AI/ads, panels, packages, reports and a premium UI — are largely backend-only or absent. `tools/ai_state.py` requires roadmap phase weights to sum to 100, so new phases need an explicit rebaseline.

## Proposed decision

1. Add PHASE-17..PHASE-23 as defined in `PRODUCT-TRACK-PLAN.md`, task IDs TASK-0102..TASK-0137 (TASK-0101 is already used; the email-tracking extension's candidate IDs are superseded and its scope moves into TASK-0113).
2. Rebaseline weights: completed PHASE-00..16 keep their relative shares inside a 50% "foundation" block; PHASE-17..23 share the remaining 50%. Materialize machine tasks only when PHASE-17 is activated, through `tools/ai_txn.py`.
3. UI stack: keep Laravel + Inertia + React 19 + Tailwind v4 and add an accessible component system; do not rewrite in Next.js. Revisit only if a separately deployed public site or mobile client is required.
4. Every phase follows the AI-native lifecycle in section 2 of the plan, starting with research and competitor comparison.

## Consequences

Roadmap progress will drop from 100% to about 50% at activation; this is expected. Live providers, payments and ads still require owner-supplied credentials and approvals.
