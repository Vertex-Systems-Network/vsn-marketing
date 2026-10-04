# TASK-0076 durable messaging boundary revalidation

Observed 2026-10-04. Scope: offline capability candidates and durable synthetic operation identity; no live provider activation.

## Official sources and findings

- [Laravel 13 query builder](https://laravel.com/framework/docs/13.x/queries), retrieved 2026-10-04: pessimistic locks belong in transactions; insert-or-ignore behavior differs across database engines. Canonical database is PostgreSQL, SQLite is local test support. Validate input before insertion and read back the exact scoped row; a suppressed insert is never success without a persisted matching reservation.
- [Laravel PostgreSQL grammar](https://github.com/laravel/framework/blob/13.x/src/Illuminate/Database/Query/Grammars/PostgresGrammar.php), retrieved 2026-10-04: insertOrIgnore compiles to ON CONFLICT DO NOTHING.
- [PostgreSQL 18 INSERT](https://www.postgresql.org/docs/current/sql-insert.html), retrieved 2026-10-04: unique constraints arbitrate conflicting inserts and can block on competing transactions. A missing row cannot be protected by a selected-row lock. Insert under the unique workspace/idempotency key, then lock and compare the persisted channel/provider/request identity.
- [FCM token management](https://firebase.google.com/docs/cloud-messaging/manage-tokens), retrieved 2026-10-04: device token lifecycle remains an independent live activation prerequisite. No mobile integration or token inventory is claimed here.
- TASK-0075 dated provider source matrix remains the messaging channel research basis; no concrete live endpoint has been added.

## Decisions

CONFIRMS_PLAN: implement scope-bound request digests, bounded transaction retry, atomic reservation replay, row-locked outcome reconciliation and explicit ambiguity hold. The policy application service re-evaluates offline capability, consent/suppression/provider opt-out/account/rate-budget evidence before reserving. The low-level persistence contract is internal infrastructure, not an authorization endpoint.

CORRECTNESS_REPAIR: PR484 at 73de0c5 lacked current main ancestry, had a directly edited ledger mismatching its checkpoint, selected missing rows before insertion, accepted forged operation scope, and could regress state on late observations. Repair on the existing carrier; never infer success from the PR description.

Privacy: store scope-bound hashes and normalized status only; no message payload, recipient value or secret evidence. Provider operation IDs and idempotency references are opaque internal identifiers, never telemetry content.

Outcomes: succeeded means a synthetic provider operation observation, not recipient delivery/display. Late observations are ignored; same-time contradictions are refused; provider ID mismatch is always refused. Ambiguous holds only resolve through newer terminal evidence; no retry or cross-channel fallback is enabled. Polling/webhook source enums represent normalized internal evidence, not a verified public webhook ingress. Existing ingress signature/replay contracts remain mandatory before any live binding.

External prerequisites remain explicit: provider connection grant, account/brand identity, credential reference, signed/replay-protected ingress, recipient token lifecycle, actual quota accounting, body validation, production measurements and separate activation authority. No claim that these exist follows from this offline task.


## Authorization provenance correction

[Google agentMessages.create](https://developers.google.com/business-communications/rcs-business-messaging/reference/rest/v1/phones.agentMessages/create), retrieved 2026-10-04, specifies `https://www.googleapis.com/auth/rcsbusinessmessaging`. Replace the invented `rcs.agent.send` label in the predecessor candidate; a regression test rejects that label. [Azure Entra authentication](https://learn.microsoft.com/en-us/azure/communication-services/quickstarts/identity/service-principal), retrieved 2026-10-04, distinguishes actual SMS SDK authentication/resource provisioning from internal application permission. `sms.send` and `in_app.write` are explicitly classified internal permissions; neither is claimed as an OAuth grant. WhatsApp, RCS and FCM candidates carry provider OAuth scope labels. No provided scope string is proof of a live grant.
