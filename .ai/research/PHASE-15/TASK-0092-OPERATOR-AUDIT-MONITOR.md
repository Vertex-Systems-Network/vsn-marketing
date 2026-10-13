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

## Local operator endpoint extension

An authenticated, throttled GET at `/workspaces/{workspace}/autonomy/offline-incidents/{run}` exposes the monitor's count-only summary to a current `CAMPAIGN_READ` workspace member. Tenant resolution checks workspace and brand on every request. Invalid run IDs and corrupt evidence return a generic held response with HTTP 422; the response has no raw audit fields. The monitor reads at most 1025 matching rows before refusing histories above 1024, so the application does not materialize an unbounded audit history. The feature test covers missing permission, foreign workspace, empty evidence, valid synthetic history, malformed run and redaction. This endpoint is for synthetic offline triage and does not mark acknowledgement or recovery.

## Independent human acknowledgement

An authenticated, throttled POST on the same incident's `/triage` path records `investigating` or `escalated` against the **current** review event ID. The operator needs both `CAMPAIGN_READ` and `CAMPAIGN_APPROVE`, must be a different actor from the synthetic evidence writer, and cannot acknowledge a stale, absent, cross-workspace or malformed review. The writer locks the workspace, rechecks the complete review chain and current permission, then appends a count-bounded triage event. Triage entries carry a contiguous sequence and prior-fingerprint chain; a modified earlier decision fails closed. An identical latest same-actor request replays its existing record, while escalation cannot be downgraded against the same source review. Triage evidence contains only run ID, referenced audit ID, fixed decision, chain references, and an explicit `blocked_unverified_external_effect` recovery review status with false resolution/recovery claims; freeform notes and raw findings are excluded. Structural fingerprints are not independent cryptographic authentication against a privileged database writer. This records human acknowledgement and blocked recovery review, **not** verified production incident response or a release of any hold. The source review stays held after acknowledgement. The review append itself now refuses to create entry 1025, matching the reader's 1024-entry bound.

An authenticated `/review` Inertia page presents the same bounded counts, persisted chain-validated triage status, and clear offline-only recovery block. The two triage controls appear only for a separate operator with current approval permission and a current audit event; the writer sees no actionable controls. A failed integrity check renders a generic unavailable state. The POST redirects back to the review for Inertia requests, with a brief outcome notice. Feature and component tests cover the displayed scope, unavailable state, independent controls, exact event reference and persisted escalation. There is no run listing or real incident recovery UI.

## Explicit out-of-scope work

TASK-0092 AC-3 is **not done**: real human triage acknowledgement/status transitions, incident response and supervised recovery, independent production telemetry, retention/legal controls and end-to-end operator UI are not asserted. AC-1 and AC-2 likewise remain open pending holistic certification of the attack suite and negative provider/queue failures. No live rollout, release or authority increase is permitted.

Require full exact-head Foundation, PHP 8.3 floor, PostgreSQL integration, browser E2E, Security/Governance and resulting-main checks before merge. Progress snapshot stays 95.00% overall and 75.00% PHASE-15.
