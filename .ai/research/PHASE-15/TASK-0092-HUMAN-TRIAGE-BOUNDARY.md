# TASK-0092 — Human-only synthetic incident triage acknowledgement

Status: **independent AC-3 staged candidate**, not certified or production-enabled.

## Risk and current architecture

Existing TASK-0092 PR #565 introduced a strict, default-deny synthetic-only red-team evidence reader. PR #566 added canonical `audit_events` persistence with a monotonic per-run hash-linked sequence. An incident read-only review is not a human triage or recovery record. A model or an old apparently positive synthetic fixture must never impersonate a privileged human, resolve a real incident, or erase a newer unresolved hold.

This bounded follow-on uses established VSN Marketing `Auth` session, `WorkspaceAuthorizer`, `PermissionCatalog::AI_APPROVE`, `AuditRecorder`, tenant-owned workspace lock and the current hash-linked synthetic audit source. No new migrations, routes, permissions, provider APIs, secret fields or side-effect flows are introduced.

## New candidate behavior

- Only an **authenticated human** with current independent `AI_APPROVE` permission may append a synthetic `investigation_requested`, `acknowledged` or `escalated` note; the requester and reviewer must be distinct actors.
- Independently prove organization→workspace ownership and read the **entire current synthetic review chain** under the workspace row lock, bounded to 1024 events. Verify per-run sequence continuity, previous fingerprint linkage, exact tenant/brand/requester, synthetic-only truth flags and canonical status. The chosen source event must be the **latest** verified event; earlier timestamps/UUID sort are not accepted as chronology.
- Persist a minimal append-only audit note referencing only exact source event/fingerprint, decision, actor, case count and offline status. Never store raw prompts, attack payloads, recipient data, credentials or actual provider details.
- Duplicate same human/source/decision notes return their existing audit receipt. Malformed prior triage audit evidence fails closed instead of being ignored.
- Every result explicitly returns `production_incident_verified=false`, `incident_resolved=false`, `recovery_performed=false`, `execution_authorized=false`, `promotion_authorized=false`. A note is not an action, incident clearance, recovery, live customer communication or approval for a provider effect.

## Adversarial tests and limits

Staged feature tests include authenticated allowlist, replay suppression, independent actor and role revocation, session spoof/self-grant, forged organization and source reference, unsafe triage outcome, stale event after a newer unresolved hold with potentially colliding timestamps, and corrupt prior audit history.

TASK-0092 AC-3 remains incomplete until broader incident monitoring, retention, operator UX, actual human response process and holistic full CI pass. AC-1 prompt/source exfiltration coverage and AC-2 provider/queue stop/replay races are separately pending. Requires exact-head Foundation/PHP 8.3/PostgreSQL/E2E/Security/Governance and resulting-main checks before merge. Nothing authorizes production sends, spend, publication, refund, rollback or actual incident mitigation.
