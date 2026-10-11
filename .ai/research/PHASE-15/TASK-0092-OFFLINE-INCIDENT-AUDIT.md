# TASK-0092 — Durable Synthetic Red-Team Audit and Replay Guard

Status: **AC-3 partial implementation, NOT TASK COMPLETION**. This follows PR #565's strictly offline, deny-by-default synthetic evidence reviewer.

## Design boundary

- Reuse the canonical append-only `audit_events` sink and `AuditRecorder`; do **not** introduce an incident store, provider adapter, user-facing incident-route, database migration, external queue, or automatic recovery.
- The `DatabaseBoundedAutonomyOfflineRedTeamAudit` records only the exact `BoundedAutonomyOfflineRedTeamIncidentReview` result. It rejects unexpected authority flags, malformed category/severity counts, unsupported statuses, and any raw prompt/secret/recipient payload fields (the upstream reviewer rejects those).
- Independently authorize a current scoped operator with `ExperimentAccess::allows(..., CAMPAIGN_READ)`, verify workspace ownership and serialize same-workspace appends under an exclusive row lock. A later replay cannot silently change actor, brand, run or organization.
- The persistent fact set includes canonical fingerprints, bounded case counts, category/severity tallies, *synthetic only* provenance and `production_incident_verified=false`/`recovery_performed=false`. No raw payload, secret, credential, prompt or full source fixture enters the audit event.
- A repeat of the latest review returns its stable audit event without a duplicate append. A later synthetic pass cannot erase a prior unresolved/unknown hold; it remains `held_offline` pending an **independent future human recovery path**. No positive offline review grants execution, promotion, confirmed breach, actual remediation or provider authority.
- The checksum proves internal consistency against drift, **not** source authentication or observed production effectiveness. Actual provider compromise detection and breach response need separately verified telemetry and human procedures.

## Acceptance and verification

The new feature tests exercise replay suppression, cross-actor and foreign-organization spoof rejection, permission revocation, aggregate-only audit redaction, unknown-source holds, simulated pass → unresolved hold → old pass replay, and rejection of secret-bearing fixture fields before any audit append.

This addresses part of TASK-0092 **AC-3** (durable synthetic audit and conservative replay), while real monitoring/provenance, incident case triage, authenticated human recovery, retention policy and operator UX remain unaccepted. **AC-1/AC-2/AC-3 all remain false** pending holistic adversarial/failure certification.

Require exact-head and resulting protected-main Foundation, PHP 8.3, PostgreSQL integration, browser E2E, Security and Governance CI. No real production sending, spending, incident verification or recovery authorization is added.
