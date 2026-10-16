# ADR-0007 — Product track PHASE-17..24 and roadmap rebaseline

Status: **ACCEPTED** (owner decisions 2026-10-17: Next.js web, Expo Android/iOS, seller = reseller/agency + marketplace + affiliate)
Date: 2026-10-17

## Context

The PHASE-00..16 baseline is complete (roadmap 100%), but the owner's product requirements R1–R21 (`.ai/roadmap/PRODUCT-TRACK-PLAN.md`) — live email/social/AI/ads, panels, packages, reports and a premium UI — are largely backend-only or absent. `tools/ai_state.py` requires roadmap phase weights to sum to 100, so new phases need an explicit rebaseline.

## Proposed decision

1. Add PHASE-17..PHASE-24 as defined in `PRODUCT-TRACK-PLAN.md`, task IDs TASK-0102..TASK-0142 (TASK-0101 is already used; the email-tracking extension's candidate IDs are superseded and its scope moves into TASK-0113).
2. Rebaseline weights: completed PHASE-00..16 keep their relative shares inside a 50% "foundation" block; PHASE-17..24 share the remaining 50%. Materialize machine tasks only when PHASE-17 is activated, through `tools/ai_txn.py`.
3. Stack (owner decision 2026-10-17): Laravel stays as the API backend; the web client moves to Next.js and Android/iOS apps are built with React Native + Expo, sharing a generated TypeScript API client and design tokens in a pnpm/Turborepo monorepo (`PRODUCT-TRACK-PLAN.md` section 2). Existing Inertia screens remain until their Next.js replacements pass E2E.
4. Every phase follows the AI-native lifecycle in section 3 of the plan, starting with research and competitor comparison.

## Consequences

Roadmap progress will drop from 100% to about 50% at activation; this is expected. Live providers, payments and ads still require owner-supplied credentials and approvals.
