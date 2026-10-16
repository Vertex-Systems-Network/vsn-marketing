# Blockers

## PHASE-16 — Resolved by owner decision ADR-0006

- Timestamp: `2026-10-16T00:00:00+00:00`
- Status: RESOLVED for acceptance purposes. The repository owner explicitly decided (ADR-0006, 2026-10-16) that Phase 16 is executed by AI only: TASK-0095 provider interoperability uses the automated local OIDC IdP test; TASK-0096 residency is EU-only; TASK-0098 billing is Stripe test mode only with live charges disabled; TASK-0099 objectives are RPO 24h / RTO 4h exercised in CI/container environments.
- Remaining non-blocking boundary: production activation of a real IdP, non-EU regions, Stripe live mode or a production backup target each needs a new owner decision. Acceptance criteria, adversarial tests and exact-head gates are unchanged.

## TASK-0024 — Resolved (reconciled 2026-10-16)

- Status: RESOLVED. The Delivery owner (`wpessential`) approved threshold set `task0024-v7-exact-human-approved-20260915` on 2026-09-15. `docs/operations/TASK-0023-DELIVERY-SLOS.md` carries the approved values with no `TBD_MEASURED`, and journal event #58 records the guarded TASK-0024 -> TASK-0025 transition. The earlier blocked text was stale; issue #134 is closed against this evidence.
