# TASK-0016 Research — Provider-neutral connector contracts

Observed: 2026-09-01 (revalidated against current official provider documentation)

## Purpose

Revalidate volatile provider behavior before defining adapter, error, quota, webhook, asynchronous reconciliation, and API-version/deprecation contracts. These sources shape provider-neutral primitives only. Concrete Amazon SES, Brevo, and Gmail adapters remain TASK-0017 work.

## Current source evidence

### Amazon SES

- Service quotas: https://docs.aws.amazon.com/ses/latest/dg/quotas.html
- Sending-limit behavior: https://docs.aws.amazon.com/ses/latest/dg/manage-sending-quotas.html
- SendQuota API shape: https://docs.aws.amazon.com/ses/latest/APIReference-V2/API_SendQuota.html

Observed constraints:

- SES quotas are scoped per AWS Region.
- Sending quota is rolling over the prior 24 hours and is recipient-based rather than message-count based.
- Per-second and rolling-24-hour limits coexist and account values vary; numeric defaults are volatile account/provider facts, not core constants.
- Runtime quota snapshots therefore need operation, region/scope, unit, window, current limit/remaining/reset evidence, and provenance.

### Brevo

- API rate limits: https://developers.brevo.com/docs/api-limits
- Rate-limit response headers: https://developers.brevo.com/docs/limit-headers
- Webhook security strategies: https://developers.brevo.com/docs/secured-webhooks

Observed constraints:

- Rate limits can coexist across RPS/RPH windows and vary by endpoint/account tier.
- 429 responses and `x-sib-ratelimit-limit`, `x-sib-ratelimit-remaining`, and `x-sib-ratelimit-reset` are dynamic runtime evidence.
- Webhook authenticity is not universally an HMAC-signature model: current Brevo documentation describes IP allowlisting, basic credentials, bearer tokens, and custom headers.
- A generic `supports_webhook_signature` boolean would therefore be incorrect. Verification must be strategy-owned by the connector and unsupported/unknown verification must fail closed when authenticity is required.

### Gmail API + Google Cloud Pub/Sub

- Gmail users.watch API: https://developers.google.com/workspace/gmail/api/reference/rest/v1/users/watch
- Gmail mailbox history semantics: https://developers.google.com/workspace/gmail/api/reference/rest/v1/users.history/list
- Google Workspace developer release notes: https://developers.google.com/workspace/release-notes
- Pub/Sub push delivery: https://docs.cloud.google.com/pubsub/docs/push
- Authenticated Pub/Sub push: https://docs.cloud.google.com/pubsub/docs/authenticate-push-subscriptions

Observed constraints:

- Gmail API remains on the `v1` service surface in current Google documentation and release notes; provider API/version provenance must be recorded rather than inferred from SDK package versions.
- `users.watch` establishes/renews a mailbox watch and returns both `historyId` and an expiration; the watch must be renewed before expiration.
- Mailbox change reconciliation uses `history.list` from a prior `historyId`; history IDs are increasing but non-contiguous, and an invalid/out-of-date start history ID can require a full sync.
- Gmail quota behavior is provider-owned and can change independently of application releases; Google announced Gmail API quota-unit changes in 2026, reinforcing that runtime/documented quota evidence must not be frozen into canonical constants.
- Gmail notifications flow through Cloud Pub/Sub, so a notification is a reconciliation trigger rather than necessarily the complete canonical business event.
- Pub/Sub push may be authenticated with a signed JWT in the Authorization header, and delivery can be retried when the endpoint does not acknowledge successfully.
- The contract must therefore separate webhook/push authenticity, delivery deduplication, provider operation or history cursor reconciliation, and final canonical-event persistence.

## Architecture decisions for TASK-0016

1. Connector capability support remains independent from connection readiness. Missing operations resolve to explicit `unknown` and are never implicitly supported.
2. Connector manifests carry connector version, API-version strategy, documentation provenance, observation time, deprecation/sunset metadata, and sandbox/test limitations.
3. Errors normalize into stable categories (`retryable`, `rate_limited`, `authentication`, `authorization`, `validation`, `unavailable`, `permanent`, `unknown`) while retaining provider code/status/evidence.
4. Retry decisions are category-driven. `unknown` is fail-closed and is not automatically retryable.
5. Quota extraction returns zero or more runtime signals; simultaneous scopes/windows/units are first-class. Current provider numeric limits are never frozen into canonical code constants.
6. Webhook requests preserve raw body bytes. Verification is connector-owned and strategy-driven; required authenticity rejects `unsupported`, `unknown`-equivalent, or rejected verification.
7. Replay/deduplication requires a connector-derived stable delivery/event key. Duplicate claims are rejected before downstream canonical event work.
8. HTTP/API acceptance is not business completion. Provider operations retain a canonical idempotency key, optional provider operation ID, non-terminal accepted/pending/in-progress states, and terminal succeeded/failed/cancelled states.
9. Polling and webhook observations reconcile the same provider operation through a monotonic canonical progression (`unknown` → `accepted` → `pending` → `in_progress` → terminal, with direct forward jumps permitted). Unknown or regressive non-terminal observations do not advance state, and terminal state cannot be silently rewritten.
10. TASK-0016 defines provider-neutral contracts and negative behavior tests only; reference provider SDK imports/branches remain forbidden until TASK-0017.
