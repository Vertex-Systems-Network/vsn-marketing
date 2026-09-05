# TASK-0019 Research — Delivery-engine semantics and current provider constraints

- researched_at: 2026-09-06T03:20:00+05:00
- task: TASK-0019
- phase: PHASE-04
- scope: Provider-neutral queue, backpressure, idempotency, retry/failover, scheduling, reconciliation and measurable acceptance semantics for the later DeliveryEngine implementation. No product implementation is authorized by this document.
- runtime baseline: Laravel 13.x with Horizon/Redis queue support in the repository
- reference delivery connectors: Amazon SES, Brevo and Gmail API

## Decision

Proceed with a **provider-neutral delivery operation + attempt ledger** in PHASE-04. Queue execution and provider delivery are separate trust/state machines.

A queue job being unique, non-overlapping, completed, failed or retried does **not** prove whether a remote provider accepted a send. Therefore later implementation must never use queue success/failure alone as delivery truth and must never blindly fail over or replay an ambiguous provider outcome.

Canonical delivery logic must branch on normalized capabilities/state/error classes, not provider names.

## Current authoritative sources

| Area | Official source | Accessed | Implementation impact |
|---|---|---:|---|
| Laravel queues | https://laravel.com/docs/13.x/queues | 2026-09-06 | Current uniqueness, overlap locks, exception throttling, retry/timeout, failed-job and queue-backend failover semantics. |
| Laravel HTTP client | https://laravel.com/docs/13.x/http-client | 2026-09-06 | Explicit request retry/backoff exists but must be constrained by delivery idempotency/ambiguity rules. |
| Amazon SES limits | https://docs.aws.amazon.com/ses/latest/dg/manage-sending-quotas.html | 2026-09-06 | Region-scoped rolling 24h recipient quota and account-specific per-second rate. |
| Amazon SES quota errors | https://docs.aws.amazon.com/ses/latest/dg/manage-sending-quotas-errors.html | 2026-09-06 | SES rejects quota-exceeded sends and exposes throttling errors; retry must be delayed/bounded. |
| Amazon SES quotas | https://docs.aws.amazon.com/ses/latest/dg/quotas.html | 2026-09-06 | Sandbox defaults and recipient-counted quota semantics; production quotas vary by account/use case. |
| Brevo API limits | https://developers.brevo.com/docs/api-limits | 2026-09-06 | Endpoint/tier RPS/RPH limits; 429 behavior; limits are not one universal account constant. |
| Brevo limit headers | https://developers.brevo.com/docs/limit-headers | 2026-09-06 | Runtime limit/remaining/reset evidence should drive throttling/backoff. |
| Brevo transactional send | https://developers.brevo.com/docs/send-a-transactional-email | 2026-09-06 | Transactional send surface remains the reference delivery call. |
| Gmail API quotas | https://developers.google.com/workspace/gmail/api/reference/quota | 2026-09-06 | Since 2026-05-01 the documented API model is 1,200,000 units/min/project and 6,000 units/min/user/project; `messages.send` costs 100 units. |
| Gmail API errors | https://developers.google.com/workspace/gmail/api/guides/handle-errors | 2026-09-06 | 403/429 quota classes, per-user concurrency pressure, separate mail-sending limits and exponential-backoff guidance. |

## Laravel execution semantics

### Queue uniqueness is admission dedupe, not remote-send idempotency

Laravel `ShouldBeUnique` prevents duplicate queued jobs using a lock and supports bounded unique-lock lifetime. This is useful as one admission guard, but the lock is not durable evidence that an external send was or was not accepted.

Later DeliveryEngine implementation should use a durable database-backed operation key as canonical idempotency authority. Queue uniqueness may reduce duplicate work, but it must be defense-in-depth only.

### Overlap prevention

`WithoutOverlapping` can serialize jobs by a shared lock key and can apply a key across job classes. Use this only where a critical section truly requires serialization, for example one provider-connection throttle/reconciliation transition. It must not become a global throughput bottleneck.

### Exception throttling

Laravel `ThrottlesExceptions` can delay work after repeated exceptions and supports keyed/shared throttling plus conditional handling. This maps well to a provider-connection circuit-breaker input, but the canonical breaker state must remain explicit/observable rather than hidden only in middleware cache state.

### Retry and timeout invariant

Laravel documents that worker `--timeout` must be several seconds shorter than queue `retry_after`; otherwise the same job may be processed twice. Later certification must assert this invariant in runtime configuration.

HTTP connect/request timeout must also be explicit because blocking I/O may otherwise exceed the job-level timeout boundary.

### Queue failover is not provider failover

Laravel 13's `failover` queue driver falls back between **queue backend connections** when dispatching queue work. It does not mean a delivery attempt may safely switch from SES to Brevo/Gmail. Delivery-provider failover requires separate delivery-state/idempotency rules below.

## Current provider constraints

### Amazon SES

- Sending quota is recipient-counted over a rolling 24-hour period and scoped independently per AWS Region.
- Maximum sending rate is account/region specific; sustained traffic must remain within current account capacity.
- Sandbox default is 200 recipient-counted emails/24h and 1 email/second; production quota is not a universal constant.
- Quota exhaustion returns provider throttling and SES does not attempt to redeliver the rejected request.
- Runtime quota/readiness observation therefore belongs in the throttle budget; hard-coded production values are invalid.

Delivery implication: a known SES throttling rejection may be safely rescheduled subject to the operation remaining unaccepted. A transport timeout with uncertain acceptance must become `ambiguous`, not an automatic replay or cross-provider failover.

### Brevo

- API request limits are endpoint/tier dependent and may include simultaneous per-second and per-hour windows.
- `POST /v3/smtp/email` has dedicated limits distinct from generic SMTP-resource endpoints.
- 429 means rate-limit exhaustion; limit/remaining/reset response headers provide current runtime evidence.
- API request-rate capacity is distinct from commercial sending credits/account deliverability constraints and must not be interpreted as guaranteed email throughput.

Delivery implication: throttle budgets must consume observed endpoint/account evidence and preserve reset timestamps. Static core constants must be only conservative fallback configuration, never claimed provider truth.

### Gmail API

Current official quota documentation states:

- 1,200,000 quota units per minute per project;
- 6,000 quota units per minute per user per project;
- `users.messages.send` costs 100 units;
- a message may have up to 500 recipients;
- Gmail's normal mail-sending limits are separate from API quota-unit limits and are shared across the user's clients;
- per-user concurrent-request limits also exist;
- 429 can persist for hours after daily mail-sending limits are reached;
- Google explicitly warns that a successful HTTP response cannot be treated as final recipient delivery proof;
- quota/rate/server errors should use exponential backoff, starting no earlier than the provider guidance permits.

The arithmetic ceiling implied by the documented per-user API quota is 60 `messages.send` calls/minute if that user performs no other Gmail API work, but this is **not** a safe throughput promise because separate mail-sending, concurrency, billing/project and account-policy limits also apply.

Delivery implication: Gmail remains a mailbox connector. Per-user fairness and concurrency must be first-class throttle dimensions; the engine must not fan out bulk marketing through Gmail merely because API-unit arithmetic appears to allow it.

## Canonical delivery contract

### DeliveryOperation

One immutable logical intent per recipient/materialized destination:

- `operation_id`
- `workspace_id`
- `message_snapshot_id`
- `recipient_snapshot_id`
- `channel`
- `idempotency_key`
- `scheduled_not_before_at`
- `priority_class`
- `state`
- `accepted_attempt_id` nullable
- `reconciliation_required_at` nullable
- timestamps/version for optimistic concurrency

Required uniqueness boundary: `(workspace_id, idempotency_key)`.

The idempotency key must derive from stable business intent, not queue job ID, provider request ID or retry attempt number.

### DeliveryAttempt

Each provider execution attempt is append-oriented evidence:

- operation/provider-connection reference
- attempt ordinal
- route decision snapshot
- quota/breaker observation snapshot
- started/finished timestamps
- normalized outcome class
- provider reference when returned
- retry-after/reset evidence when returned
- ambiguity/reconciliation marker
- sanitized error evidence

Provider IDs never become canonical idempotency authority.

## State machine

Minimum operation states:

`scheduled -> ready -> leased -> dispatching -> accepted`

Terminal alternatives:

- `permanent_failed`
- `cancelled`
- `expired`

Non-terminal safety states:

- `retry_wait`
- `backpressured`
- `ambiguous`
- `reconciling`

Rules:

1. `accepted` is monotonic for the logical operation unless later provider events enrich delivery outcome; it is never reset to permit another provider send.
2. `ambiguous` forbids automatic replay/failover until reconciliation proves the prior attempt was not accepted or an explicit owner policy authorizes duplicate risk.
3. Provider delivery/bounce/open events enrich message outcome separately from send acceptance.
4. Queue completion only means the worker completed its local transition; it does not mint `accepted` without provider evidence.

## Retry classification

| Class | Examples | Default action |
|---|---|---|
| permanent_validation | invalid recipient/request, unsupported capability | fail operation; no retry |
| auth_or_policy | invalid credential/scope/sender/domain policy | open/hold connection breaker; no blind retry |
| rate_limited | SES throttling, Brevo 429, Gmail quota/rate response | `retry_wait` using provider reset/retry evidence plus jitter |
| transient_pre_accept | connection failure proven before request acceptance | bounded retry on same eligible route |
| transient_server | provider 5xx where acceptance is documented as not having occurred | bounded retry with exponential backoff |
| ambiguous_transport | timeout/reset after request may have reached provider | `ambiguous`; reconcile; no blind failover |
| provider_accepted | provider accepted/reference returned | mark accepted; never reroute logical operation |

When provider documentation cannot prove whether a failure is pre- or post-acceptance, classify conservatively as ambiguous.

## Circuit breaker

Breaker key must be at least `(workspace, provider_connection, operation_class)`; optional provider-wide health may be an additional signal, not a substitute for tenant-scoped state.

States: `closed`, `open`, `half_open`.

Open on sustained transient/auth/rate failures according to explicit thresholds. Rate-limit reset may schedule half-open probing, but a breaker must not discard work; operations remain queued/backpressured with observable reason.

## Backpressure and admission

Admission/dispatch should consider all of:

- queue age/depth by priority;
- active worker capacity;
- provider connection concurrency;
- observed quota remaining/reset windows;
- rolling/day recipient budget where applicable;
- circuit state;
- workspace fairness budget;
- scheduled not-before time;
- campaign/global safety ceilings;
- reconciliation backlog.

Do not solve provider saturation by unlimited queue growth. Later implementation needs bounded admission/fair-share rules and an operator-visible saturation state.

## Provider routing and failover safety

Routing is deterministic from capability + policy + connection readiness + tenant configuration + cost/quota/health evidence.

Automatic provider failover is allowed only when the previous route is **known not accepted**. It is forbidden for ambiguous attempts by default.

A provider change creates a new attempt under the same logical operation; it does not create a new idempotency key.

No later implementation may route Gmail as a generic bulk fallback solely because SES/Brevo are saturated.

## Scheduling precision

Canonical schedules use UTC instants plus original timezone/provenance where user-local semantics matter.

Required rule: never dispatch before `scheduled_not_before_at`.

Proposed certification target for later implementation:

- under normal capacity, 99% of eligible scheduled operations begin dispatch within 30 seconds of not-before time;
- under provider/workspace backpressure, lateness is explicit and attributed rather than hidden as scheduler drift;
- clock-skew/late-worker tests prove no early send;
- DST/local-time materialization is performed before immutable delivery intent is queued.

This is an internal acceptance target, not a provider SLA.

## Dead letter and reconciliation

Dead-letter conditions:

- terminal permanent failure;
- retry budget exhausted for a retry-safe class;
- operation expires before safe execution;
- invariant corruption/fail-closed validation.

Ambiguous attempts are **not** ordinary dead letters. They enter reconciliation with bounded probes/provider-event correlation/operator resolution.

Reconciliation must be idempotent and must never manufacture provider acceptance from absence of evidence.

## Proposed measurable PHASE-04 certification targets

These are engineering acceptance targets for TASK-0020+; they are not current production claims.

1. **Duplicate safety:** 0 duplicate logical sends in deterministic retry/worker-crash/lease-expiry/provider-timeout test matrix where provider acceptance is knowable.
2. **Ambiguity safety:** 100% of uncertain post-dispatch outcomes enter `ambiguous/reconciling`; 0 automatic cross-provider failovers from ambiguous state.
3. **Early-send safety:** 0 sends before `scheduled_not_before_at` across clock skew, DST materialization and restart tests.
4. **Normal schedule precision:** >=99% dispatch-start within 30s of not-before under an unsaturated reference load.
5. **Quota safety:** 0 dispatches beyond deterministic injected provider/workspace quota budgets; runtime reset/remaining evidence is honored.
6. **Fairness:** with two active workspaces under saturation, neither can starve the other beyond the configured weighted-fair-share policy.
7. **Backpressure observability:** 100% of delayed operations expose a machine-readable delay reason and age.
8. **Recovery:** queue worker/provider simulated outage recovers without losing accepted-state evidence and without replaying accepted operations.
9. **Reconciliation:** every injected ambiguous attempt remains queryable and reaches either proven accepted, proven not-accepted/retry-safe, or explicit operator-required state; no silent coercion.
10. **Latency instrumentation:** queue wait, dispatch duration, provider response time, retry delay, reconciliation age and schedule lateness are measurable per workspace/provider connection without secret/recipient-content leakage.

Load numbers must be calibrated from deployment capacity during TASK-0021/TASK-0024; TASK-0019 deliberately does not invent a production messages/second promise.

## Contradictions and resolved assumptions

### Queue failover vs delivery failover

Resolved: Laravel queue-backend failover is infrastructure dispatch resilience only. Provider delivery failover requires canonical delivery acceptance/idempotency state.

### Static provider limits

Resolved: prohibited as canonical truth. SES production quotas vary by account/region; Brevo API limits vary by endpoint/tier; Gmail has project/user quota units plus separate sending/concurrency limits.

### Retry every timeout

Rejected. Timeout after a request may be ambiguous and can create duplicate recipient sends.

### One global provider circuit

Rejected. It can create cross-tenant blast radius. Tenant/provider-connection scoped breakers are canonical; aggregate provider health may advise but cannot silently override tenant state.

### Provider HTTP success equals recipient delivery

Rejected. Provider acceptance and final delivery are separate states/events.

## Open runtime facts to discover later

Not blockers to this research contract, but must be runtime evidence before production execution:

- actual SES production sending quota/rate for each account and Region;
- actual Brevo account plan/transactional sending capacity and live response headers;
- actual Gmail project cohort/quota configuration, mailbox account sending policy and concurrency behavior;
- deployment worker/Redis/database capacity used to calibrate concrete load thresholds;
- provider-specific reconciliation/event correlation available for each configured connection.

No live credentials, paid send, production data or provider mutation was used for TASK-0019 research.

## Acceptance mapping

- AC-1: satisfied by current Laravel/SES/Brevo/Gmail official-source evidence above.
- AC-2: satisfied by the DeliveryOperation/DeliveryAttempt/idempotency/routing/throttle contract.
- AC-3: satisfied by retry classification, breaker, dead-letter, reconciliation, scheduling, backpressure and failover rules.
- AC-4: satisfied by explicit measurable later-phase certification targets without fabricating production throughput.
- AC-5: satisfied by resolved contradictions, explicit runtime unknowns and the no-implementation boundary.

## Outcome

TASK-0019 research supports the existing PHASE-04 plan without a roadmap split or provider-name branch in canonical delivery logic. The next action is exact-head repository validation/review of this research artifact. Only after accepted TASK-0019 completion may the Supervisor transactionally activate TASK-0020; no DeliveryEngine implementation is authorized by this research branch.
