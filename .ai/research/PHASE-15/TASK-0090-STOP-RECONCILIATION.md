# TASK-0090 Conservative Emergency-Stop Reconciliation

Status: staged after PR #534; not certified, not merged and no production activation is authorized.

## Scope

- Read current global and workspace stop evidence using the same global → quota → reservation row-lock sequence as offline resource admission.
- For a verified tenant/actor/run/snapshot with `offline_reserved` state, record `held_by_emergency_stop` exactly once when either stop activates or global authority is missing.
- Preserve cost reservations and all resource counters: no refund without independently verified settlement evidence.
- For an unknown or potentially irreversible outcome, hold as `external_outcome_unverified` and require external operator/provider reconciliation. Do not claim a successful cancellation.
- Reject foreign actor, brand or snapshot and any invalid run format; no execution, sending, billing, publishing or promotion capability is added.

## Risk and evidence boundaries

This service cannot revoke messages or campaign updates already delivered by an external provider. It can only preserve the stop decision and offline durable evidence, refusing any inference that unknown external activity has been cancelled. A future provider adapter must deliver independently sourced terminal receipts before anyone can settle or refund uncertain usage.

## Test matrix

- No active stop is not misreported as a completed cancellation.
- Global or workspace stop holds a prior offline reservation, idempotently.
- Reserved cost, token, volume and attempt counters are never released by stop alone.
- Missing global authority fails closed.
- Foreign tenant actor/changed fingerprint is rejected.
- Unknown external outcome remains unverified and cannot silently resume.

### Certification gate

Run task-focused Laravel feature tests and the full exact-head Foundation, PHP floor, PostgreSQL integration, E2E, Security Supply Chain and governance checks; only then merge a standalone PR. TASK-0090 AC-3 stays pending until full last-side-effect and independent outcome reconciliation evidence exists.
