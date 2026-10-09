# TASK-0090 — Independent autonomy safety acceptance matrix

Status: **IN PROGRESS / offline-only**. This document does not complete the task.

| Acceptance | Verified carriers | Unclosed evidence |
| --- | --- | --- |
| AC-1 — bounded multi-resource admission, concurrency and last-boundary recheck | #531 preflight, #533 transactional offline quota and PostgreSQL contention, #537 locked final offline review; #538 rate-window contract staged | Certify #538 exact head/resulting main; certify independent per-minute policy in final review, and test rate/stop concurrency. Live external effects are intentionally disabled. |
| AC-2 — immutable independent approval | #532 exact audience/content/destination/cost/time policy matching, #534 durable latest human decision and current role check, #536 authenticated human recorder | Serialize human approval/revocation with final stop review; require no self-grant, forged organization or revoked authority in final test suites. |
| AC-3 — global/workspace stop and unknown outcomes | #531 default denial, #533 locked reservations, #535 no inferred/refunded irreversible outcome, #537 late stop and approval review | Verify cross-organization stop reconciliation denial and PostgreSQL stop/admission race fixture; no provider outcome can be invented. |

## Threat and boundary

All successful reviews and offline reservations return `execution_authorized=false` and `promotion_authorized=false`. No provider send, queue, ad spend, billing, secret use, source code/policy self-change, or canary promotion is enabled. Human approvals are necessary evidence but **not** authorization to perform external effects. Consent, suppression, jurisdiction and provider-specific authorization must be independently checked if future scope creates an actual side effect.

The proposed follow-on rechecks the owner-supplied per-minute policy inside the same lock-order family as quota admission and rejects fake organizations during stop reconciliation and approval recording. A stop must serialize with revocation recording, and operator reviews remain read-only. A missing rate row, changed policy or missing human authority fails closed.

## Certification discipline

Only exact-head unit, PostgreSQL integration, Foundation/PHP, browser E2E, Security and AI Continuity/Governance successes and a verified protected-main merge permit terminal task acceptance. README progress must not increase merely because a development slice was pushed. Never claim production rate, billing, external send, observed conversion lift or zero downtime from synthetic tests.
