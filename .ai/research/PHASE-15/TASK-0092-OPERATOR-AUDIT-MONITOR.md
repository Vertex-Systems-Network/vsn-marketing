# TASK-0092 — Tenant-Scoped Read-Only Operator Incident Monitoring

Status: **AC-3 partial implementation candidate, not task completion**.

## Existing baseline

PR #565 introduced strict synthetic red-team evidence envelopes and a deny-by-default source. PR #566 added a transactional, append-only synthetic audit with per-run sequence/chained fingerprints and a permanent hold after unresolved evidence. PR #568 merged deterministic provider-like typed-tool error and failed rollback redaction tests on protected main `9d2934dac293675c05f9c5a9edf382834019d46e`; resulting-main checks are separately required.

## This slice: bounded operator monitoring

- `DatabaseBoundedAutonomyOfflineRedTeamMonitor` reads the existing `audit_events` ledger. It does **not** write incidents, send communications, call a provider, clear holds, trigger a queue or make recovery decisions.
- Requires the current operator's `CAMPAIGN_READ` permission and verified organization/workspace ownership. Brand scope must exactly match every audited entry, while a different *authorized* operator in the same brand can read the audit summary without impersonating the writing analyst.
- Checks a maximum 1024 audit rows per run, expected typed keys, event correlation, sequence contiguity, review-fingerprint chain, case tally completeness and the contract's permanently held states. It fails closed on modified records, missing/gapped sequences, duplicate sequence IDs, cross-brand rows or unsupported resolved/production claims.
- Emits only synthetic case count, category/severity aggregates, audit-event reference, sequence and hold/triage reason. Raw prompts, secret-bearing evidence, operator IDs, provider payloads and full attack fixtures never leave the query.
- Missing audit evidence is `held_offline`; a later synthetic pass cannot supersede a historical unresolved hold. Output **always** keeps `production_incident_verified=false`, `incident_resolved=false`, `recovery_performed=false`, `execution_authorized=false`, `promotion_authorized=false`.
- Ledger consistency is an internal bounded structural check, **not** tamper-proof cryptographic authentication. A database writer could rewrite all records/hashes; independently signed or externally anchored event evidence would be needed for that threat model.

## Adversarial acceptance fixtures

Feature tests cover an empty audit; read by a second authorized same-brand analyst; denied permission and cross-org/brand access; count-only redaction; preservation of pass -> unresolved -> old pass as a hold; tampered production-verified evidence rejected. Regression runs against the actual SQLite-backed canonical audit repository; PostgreSQL suite and CI remain required for protected promotion.

## Explicit out-of-scope work

TASK-0092 AC-3 is **not done**: real human triage acknowledgement/status transitions, incident response and supervised recovery, independent production telemetry, retention/legal controls and end-to-end operator UI are not asserted. AC-1 and AC-2 likewise remain open pending holistic certification of the attack suite and negative provider/queue failures. No live rollout, release or authority increase is permitted.

Require full exact-head Foundation, PHP 8.3 floor, PostgreSQL integration, browser E2E, Security/Governance and resulting-main checks before merge. Progress snapshot stays 95.00% overall and 75.00% PHASE-15.
