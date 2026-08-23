# PHASE-02 — Customer Data Core

## Purpose

Establish VSN Marketing's provider-neutral customer data core for contacts, identities, lists, consent evidence, and canonical customer events. This phase implements the canonical data entities already defined by the project charter, master architecture, module registry, and canonical data model; it does not introduce provider-specific ownership of customer identity.

## In scope

- `Contact`, `ContactIdentity`, and `Company`
- `List`, `Tag`, and deterministic contact membership
- append-oriented `ConsentRecord` evidence and effective-consent queries
- persisted canonical `Event` and `EventType` customer timeline data
- workspace isolation, brand scope where applicable, auditability, deterministic identity/consent behavior, and integration coverage

## Explicitly out of scope

- provider adapters and provider synchronization (`PHASE-03`)
- delivery routing/retry/failover (`PHASE-04`)
- suppression and deliverability controls (`PHASE-05`)
- canonical templates/content (`PHASE-06`)
- campaigns (`PHASE-07`)
- segments and the segment compiler (`PHASE-08`)
- journeys (`PHASE-09`)

## Permanent invariants applied in this phase

- Organization → Workspace → Brand remains the tenant hierarchy.
- All tenant-owned records carry a workspace boundary; brand identity is carried where applicable.
- External provider IDs are references, never primary domain identity.
- Consent history is append-oriented and evidence-preserving.
- Consent is a deterministic execution gate and cannot be bypassed by AI.
- Canonical events, not untrusted provider payloads, drive business behavior.
- State-changing actions remain auditable.
- Domain modules depend on canonical contracts, never concrete provider SDKs or another module's infrastructure internals.

## Task chain

| Task | Weight | Purpose |
|---|---:|---|
| TASK-0008 | 25 | Canonical contact, identity, and company foundation |
| TASK-0009 | 20 | Lists, tags, and deterministic contact membership |
| TASK-0010 | 25 | Append-oriented consent evidence and eligibility queries |
| TASK-0011 | 20 | Canonical customer-event persistence and contact timelines |
| TASK-0012 | 10 | PHASE-02 isolation, quality, and completion gate |

Weights total 100 for PHASE-02 progress calculation.

## Exit criteria

PHASE-02 may complete only when:

1. Contact, ContactIdentity, Company, List, Tag, ConsentRecord, Event, and EventType have canonical workspace-scoped persistence and domain/application contracts.
2. Cross-workspace reads, writes, references, memberships, consent evidence, and event access fail closed in automated tests.
3. External provider identifiers remain references rather than canonical identity.
4. Consent history is append-oriented, evidence-preserving, and exposed through deterministic effective-consent queries without implementing PHASE-05 suppression policy.
5. Customer events use the canonical event foundation from TASK-0006, are duplicate-safe, and can form a tenant-safe contact timeline.
6. Backend tests, integration tests, architecture/static/formatting checks, frontend gates where affected, Playwright critical smoke, and AI continuity are green on the exact acceptance head.
7. No PHASE-03+ capability is silently pulled forward.
