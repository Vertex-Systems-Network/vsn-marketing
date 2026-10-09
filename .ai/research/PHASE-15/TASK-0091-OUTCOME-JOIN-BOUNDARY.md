# TASK-0091 — Frozen Cohort to Outcome Binding (Offline Review)

Status: **staged candidate; not certified or deployed**. No positive external outcome provider is bound.

## Preconditions (all fail closed)

1. Current operator has experiment-read permission and matches the canonical workspace and organization.
2. The canonical experiment remains active, its exact plan fingerprint matches, and approval belongs to a different actor than the creator.
3. An immutable, independently vetted frozen cohort receipt exists with an exact workspace, brand, experiment ID and assignment manifest fingerprint.
4. The separately trusted outcome join source references the exact receipt ID, analysis fingerprint, frozen plan, assignment manifest and outcome source manifest, with fresh bounds.
5. Diagnostics attest no quarantine, crossover or duplicate events. Unknown or contaminated observations hold for review.
6. The independent scoring source's manifest must match the join's outcome manifest. A missing scoring source holds; passing statistical tests can only create an operator-review candidate, never an automatic winner.

The join-source interface is a capability boundary, **not** a cryptographic signature verifier by itself. Default source denies. Before treating any evidence as authentic, a future provider adapter must independently verify provenance, consent, immutable assignment IDs, event replay and the fixed analysis horizon. Do not substitute an AI-generated flag or fabricated outcome for actual external verification.

## Forbidden authority

This read-only path never creates an audience, enrolls contacts, triggers a provider, publishes content, spends money, changes an experiment arm or promotes a winner. All results report `execution_authorized=false`, `promotion_authorized=false`, `external_outcome_proven=false`.

## Remaining acceptance

- Feature/adversarial tests on permission revocation, changed frozen receipt or manifest, forged cross-organization joins, stale evidence and absent sources.
- Full exact-head Foundation, PHP floor, PostgreSQL integration, browser E2E, Security and Governance; resulting-main verification.
- Separately independently verified real outcome adapter and human promotion/rollback authority remain out of scope until explicitly certified. No production causal-lift claims.
