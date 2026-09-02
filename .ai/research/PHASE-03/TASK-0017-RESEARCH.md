# TASK-0017 Research Pack

- researched_at: 2026-09-03T03:40:00+05:00
- task: TASK-0017
- phase: PHASE-03
- scope: Fresh revalidation of the initial reference connector set, current authentication/readiness, quota/rate-limit, sandbox/test, event/webhook and retry/idempotency constraints for Amazon SES, Brevo and Gmail API before writable connector implementation begins.
- researcher: OpenAI development AI using current official/primary provider documentation plus canonical repository evidence

## Decision

Retain **Amazon SES + Brevo + Gmail API** as the TASK-0017 reference connector set. No roadmap replacement, task split or new ADR is required by this revalidation.

The trio still provides intentionally different contract pressure:

- Amazon SES is a regional delivery service with sandbox/production readiness, recipient-based rolling quotas and runtime account quota discovery.
- Brevo is a delivery/marketing platform with transactional send, multi-window endpoint rate limits, explicit request-only sandbox behavior and configurable webhook security mechanisms.
- Gmail API is a delegated mailbox API with OAuth scope/verification constraints and project/user quota-unit semantics; it must remain logically distinct from bulk marketing delivery.

The implementation must advertise only behavior actually supported and tested by each connector. Provider-specific IDs, names or branching must not leak into canonical core behavior.

## Current official sources

| Provider | Source | Accessed | Why authoritative / implementation impact |
|---|---|---:|---|
| Amazon SES | https://docs.aws.amazon.com/ses/latest/dg/quotas.html | 2026-09-03 | Official service quotas; quotas are region-specific and sending quotas are recipient-based. |
| Amazon SES | https://docs.aws.amazon.com/ses/latest/dg/manage-sending-quotas.html | 2026-09-03 | Official rolling 24-hour sending quota and region behavior. |
| Amazon SES | https://docs.aws.amazon.com/ses/latest/dg/request-production-access.html | 2026-09-03 | Official sandbox restrictions and production-access semantics. |
| Amazon SES | https://docs.aws.amazon.com/ses/latest/APIReference-V2/API_GetAccount.html | 2026-09-03 | Runtime current-region SendingEnabled and SendQuota discovery. |
| Amazon SES | https://docs.aws.amazon.com/ses/latest/APIReference-V2/API_SendEmail.html | 2026-09-03 | Current SES v2 send contract: Simple, Raw MIME and Templated content. |
| Brevo | https://developers.brevo.com/docs/using-sandbox-mode | 2026-09-03 | Sandbox `X-Sib-Sandbox: drop` semantics; success response is request-format validation only. |
| Brevo | https://developers.brevo.com/docs/api-limits | 2026-09-03 | Current plan/endpoint-specific RPS and RPH limit model and 429 behavior. |
| Brevo | https://developers.brevo.com/docs/limit-headers | 2026-09-03 | Dynamic limit/remaining/reset response headers and backoff guidance. |
| Brevo | https://developers.brevo.com/docs/transactional-webhooks | 2026-09-03 | Transactional delivery/engagement webhook event model. |
| Brevo | https://developers.brevo.com/docs/secured-webhooks | 2026-09-03 | Supported webhook protection mechanisms: source IP restrictions, basic credentials, bearer tokens and configured headers. |
| Gmail API | https://developers.google.com/workspace/gmail/api/reference/quota | 2026-09-03 | May 1, 2026 quota model transition and per-project/per-user quota-unit model. |
| Gmail API | https://developers.google.com/workspace/gmail/api/release-notes | 2026-09-03 | Official May 1, 2026 quota-model announcement/change record. |
| Gmail API | https://developers.google.com/workspace/gmail/api/auth/scopes | 2026-09-03 | Sensitive/restricted OAuth scope and verification/security-assessment requirements. |
| Gmail API | https://developers.google.com/workspace/gmail/api/reference/rest/v1/users.messages/send | 2026-09-03 | Current mailbox send method and accepted OAuth scopes. |
| Gmail API | https://developers.google.com/workspace/gmail/api/guides/sending | 2026-09-03 | RFC 2822 MIME + base64URL message construction/send flow. |

## Amazon SES revalidation

### Capability and readiness

SES remains the delivery reference connector.

Current official documentation confirms:

- sending quotas are scoped independently per AWS Region;
- sandbox sending is limited to 200 recipient-counted sends per rolling 24 hours and 1 send per second;
- while sandboxed, recipients must be verified identities/domains or the SES mailbox simulator;
- production access is region-specific and does not remove the requirement to use a verified sending identity;
- `GetAccount` exposes current-region `SendingEnabled` plus runtime `SendQuota` information;
- SES v2 `SendEmail` supports Simple, Raw/MIME and Templated message forms.

### Contract requirements

The connector must therefore:

1. Treat `region` as part of connection/readiness provenance, not merely transport configuration.
2. Discover current sending readiness and quota from provider state when available instead of freezing defaults in product code.
3. Model quota dimensions as recipient-counted rolling-24-hour capacity plus per-second rate with the provider/account/region source and observation timestamp.
4. Fail closed when the connection is sandbox-only for a recipient that cannot legally be used under SES sandbox rules.
5. Preserve provider request/response identifiers only as connector provenance/reference data.
6. Treat Simple/Raw/Templated support as connector capabilities rather than new core provider branches.

### Retry/idempotency implication

The current `SendEmail` request contract does not document a provider idempotency token. TASK-0017 must not assume network timeout means the provider did not accept the send. Retry safety must be owned by the canonical operation/idempotency layer: known pre-acceptance/transient failures may be retried under bounded policy, while ambiguous post-dispatch outcomes require an explicit unknown/reconciliation path rather than blind duplicate sends.

## Brevo revalidation

### Capability and readiness

Brevo remains the delivery/marketing-platform reference connector.

Critical current behavior:

- transactional email uses the Brevo SMTP email API surface;
- API rate limiting is endpoint/plan dependent and can simultaneously apply RPS and RPH limits;
- a 429 signals rate-limit exhaustion;
- rate-limited responses expose limit, remaining and reset metadata, so runtime values should be captured as quota evidence rather than hard-coded as durable product constants;
- sandbox mode is enabled with `X-Sib-Sandbox: drop`;
- sandbox requests can return HTTP 201 with a `messageId`, but **no email is sent and no Brevo email log is created**;
- Brevo explicitly states sandbox mode validates request format only;
- transactional webhook events include delivery/failure/engagement signals;
- webhook protection is configurable and may use provider IP ranges, username/password, bearer authorization or configured request headers.

### Contract requirements

The connector must therefore:

1. Represent Brevo sandbox success as `test/validation accepted`, never `delivered`, `sent-to-recipient` or production readiness evidence.
2. Capture rate-limit response metadata into the provider-neutral quota signal contract with freshness/provenance.
3. Normalize 429 as a retryable rate-limit class with provider reset information where present.
4. Advertise webhook support only when the configured verifier strategy is executable for that connection.
5. Never impose a fictional universal HMAC signature requirement. Brevo's documented webhook security is strategy/configuration driven.
6. Keep API keys/OAuth material outside canonical provider records; canonical data stores only secret references and non-secret auth/readiness metadata.

### Retry/idempotency implication

Current transactional send documentation does not establish a provider-native idempotency token that can be relied upon as the canonical duplicate-prevention mechanism. The same internal operation-key and ambiguous-outcome rules used by the provider-neutral contract must apply.

## Gmail API revalidation

### Capability and readiness

Gmail remains the mailbox reference connector, not a bulk marketing-delivery substitute.

Current official documentation confirms:

- `users.messages.send` sends a mailbox message to recipients in To/Cc/Bcc;
- the narrow `gmail.send` scope is sufficient for the send operation; broader scopes are available but should not be requested without a proven need;
- Gmail messages are RFC 2822 MIME content encoded as base64URL in the `raw` message field;
- sensitive/restricted Gmail scopes can trigger OAuth verification requirements, and server-side handling of restricted-scope data can require a security assessment;
- Gmail API usage limits changed on May 1, 2026;
- projects that had used the API between November 2025 and April 2026 can retain previously configured quotas, while projects created on/after May 1, 2026 are subject to the new API quota model;
- Gmail quotas are expressed in abstract quota units and enforced across provider-defined project/user dimensions.

### Contract requirements

The connector must therefore:

1. Use least privilege: prefer `gmail.send` for a send-only connector unless another operation explicitly proves the need for a broader scope.
2. Represent granted/requested scopes, OAuth verification/readiness and credential expiry/refresh state separately from basic connectivity.
3. Treat quota values as project/user/provider-provenance data. A single static Gmail quota constant is invalid because the May 2026 transition makes project cohort materially relevant.
4. Encode provider messages using standards-compliant MIME/RFC 2822 and base64URL while keeping the canonical message/content model provider-neutral.
5. Keep Gmail mailbox sending logically separate from marketing fan-out, provider routing/failover and campaign delivery behavior reserved for later phases.
6. Do not claim a provider sandbox capability that Gmail does not expose for actual delivery; deterministic tests should use transport fakes/fixtures plus separately controlled integration credentials where available.
7. Request or advertise mailbox-change/push capabilities only if TASK-0017 explicitly implements and tests them; unrelated Gmail features remain unsupported rather than implied.

### Retry/idempotency implication

The current `users.messages.send` method does not document a provider idempotency token. A successful returned Gmail message ID is provider reference evidence; a transport failure with uncertain acceptance must not be blindly replayed as though non-delivery were proven.

## Provider-neutral contract matrix

| Dimension | Amazon SES | Brevo | Gmail API | Canonical requirement |
|---|---|---|---|---|
| Connector class | delivery | delivery / marketing platform | mailbox | Provider class is metadata/capability, not a core `if provider` branch. |
| Primary auth | AWS IAM/SigV4 or provider-specific AWS credential path | API key / OAuth-capable platform auth | OAuth2 delegated user | Persist secret references only; expose non-secret auth/readiness lifecycle. |
| Readiness scope | account + region + sandbox/production + sending identity | account + sender/auth + sandbox/production behavior | Cloud project + user + OAuth scopes/verification | `authenticated` is insufficient as an executable-readiness state. |
| Quota/rate | region, recipient-counted rolling 24h + per-second | endpoint/plan, concurrent RPS/RPH + reset headers | project/user quota units; May-2026 cohort matters | Multidimensional, runtime/provenance stamped; do not hard-code volatile limits into core. |
| Test/sandbox | SES sandbox with verified targets/mailbox simulator | request-only `drop` sandbox; no delivery/log | no equivalent delivery sandbox | Test mode semantics must be explicit and provider-specific. |
| Send evidence | provider message/reference after accepted send | 201/messageId; sandbox ID is not delivery evidence | returned Message/id | Provider acceptance != final delivery; normalize operation state. |
| Event/webhook | provider event publishing/configuration-set ecosystem | transactional webhooks | mailbox API/change mechanisms are separate capability | Advertise only implemented/tested event behavior. |
| Webhook verifier | connector-owned provider strategy | IP/basic/bearer/custom-header strategy | not assumed for send-only connector | Never assume one global HMAC scheme. |
| Bulk marketing semantics | delivery transport can support application sends | platform can support delivery/marketing workflows | explicitly not the TASK-0017 bulk-delivery reference | Gmail remains mailbox-only in this phase. |

## Error, retry and reconciliation policy implied by research

TASK-0017 connector tests must distinguish at least:

- authentication/credential failure;
- missing/insufficient scope or provider authorization;
- configuration/readiness failure such as SES sandbox/identity/region constraints;
- validation/permanent request failure;
- provider rate limit with retry/reset metadata;
- transient provider/network failure known to occur before acceptance;
- ambiguous transport outcome where acceptance cannot be proven either way;
- accepted provider operation with provider reference;
- explicit unsupported capability.

Rules:

1. Safe retries require evidence that retrying cannot create an uncontrolled duplicate, or must be protected by the canonical operation/idempotency mechanism.
2. 429/backoff signals must retain provider-provided reset/quota provenance.
3. HTTP/API acceptance is not equivalent to recipient delivery.
4. Sandbox/test success must never upgrade production readiness.
5. Unknown/ambiguous dispatch outcomes must remain representable and reconcilable rather than silently coerced into failed or sent.

## Security and tenancy requirements

Research introduces no exception to existing canonical security rules:

- raw credentials/tokens/API keys never become canonical application data;
- provider connections use secret references with lifecycle metadata;
- scope/readiness/region/access tier are non-secret connection evidence;
- connector operations remain workspace/connection scoped and must fail closed across workspace boundaries;
- webhook verification is connector-owned and executable before event normalization;
- source IP restrictions may be defense-in-depth but are not assumed to be a universal authenticity proof;
- logs/errors/fixtures must not leak live secrets or mailbox/customer content.

## Findings classified against the plan

| Finding | Classification | Reconciliation |
|---|---|---|
| SES remains regional with sandbox and recipient-based rolling quota semantics | PLAN_CONFIRMATION | Keep SES as delivery reference; runtime region/readiness/quota evidence is mandatory. |
| Brevo sandbox can return 201/messageId without sending or creating a log | PLAN_CONFIRMATION / ACCEPTANCE_EVIDENCE | AC-5 tests must prove sandbox success is request validation only. |
| Brevo rate limits are endpoint/tier multi-window and expose runtime headers | PLAN_CONFIRMATION | Use `QuotaSignalExtractor`/provider-neutral quota evidence, not static core constants. |
| Brevo webhook protection is strategy/configuration driven rather than universal HMAC | PLAN_CONFIRMATION | Connector implements only documented verifier strategies; unsupported modes fail closed. |
| Gmail quota behavior materially changed May 1, 2026 and depends on project cohort | PLAN_CONFIRMATION / FRESHNESS_REQUIREMENT | Persist/provider-report quota provenance and observation time; no old global quota assumption. |
| Gmail send-only can use `gmail.send`; broader scopes increase verification/security burden | PLAN_CONFIRMATION | Least-privilege scope readiness is part of the Gmail connector contract. |
| Gmail is a mailbox send API, not the phase's bulk marketing transport | PLAN_CONFIRMATION | Preserve AC-6; no routing/failover or marketing fan-out pull-forward. |
| Current reference send methods do not provide a documented canonical idempotency token to rely on | PLAN_CONFIRMATION | Internal operation idempotency + explicit ambiguous outcome handling remain required. |
| No finding invalidates SES + Brevo + Gmail as the initial diversity set | NO_ROADMAP_CHANGE | Continue TASK-0017 exactly within current phase/task boundaries. |

## Required TASK-0017 implementation evidence

Before TASK-0017 can be accepted, the implementation/contract-matrix lanes must prove, with deterministic tests where live provider infrastructure is unavailable:

1. The same provider-neutral connector contracts can represent SES, Brevo and Gmail without provider identifiers becoming core branches.
2. Authentication/readiness failures are distinct from transport and validation failures.
3. Raw credentials are absent from canonical persistence/fixtures/log output.
4. Current quota/rate-limit signals preserve provider scope, unit/window, remaining/reset when available, provenance and freshness.
5. SES sandbox/region/readiness behavior fails closed where the requested operation is not executable.
6. Brevo sandbox success cannot be mistaken for production send/delivery evidence.
7. Gmail send remains mailbox-scoped, MIME/base64URL compliant and least-privilege aware.
8. Unsupported operations are explicit per connector.
9. Retry policy does not blindly duplicate an operation after an ambiguous send outcome.
10. Applicable webhook/event behavior passes success, malformed/authenticity/replay/duplicate tests where that connector advertises the capability.
11. Cross-workspace connector/connection/reference isolation remains fail-closed.
12. No PHASE-04 routing/failover engine or PHASE-13 social implementation is pulled into TASK-0017.

## Rejected approaches

### Treat Brevo sandbox 201 as a successful delivered email

Rejected. Brevo explicitly states sandbox mode sends nothing and creates no email log. It is request-format validation only.

### Hard-code current provider quotas into canonical business rules

Rejected. SES values vary by account/region and Gmail changed its quota model in May 2026; Brevo varies by endpoint/tier and provides runtime headers.

### Treat Gmail as another bulk transactional/marketing provider

Rejected. Gmail's reference role is mailbox behavior and delegated OAuth. Bulk marketing semantics remain outside this connector's advertised capability.

### Apply one universal webhook HMAC algorithm

Rejected. Current provider behavior is not uniform; webhook authenticity is a connector-owned strategy with explicit unsupported behavior.

### Blindly retry every timeout after send

Rejected. None of the revalidated send method contracts supplies a documented universal provider idempotency token that can make an uncertain accepted send safe to replay. Canonical operation idempotency and ambiguous-state handling are required.

## Outcome

Fresh TASK-0017 research **confirms the existing plan** and reference-provider choice. No roadmap mutation or ADR is required before implementation. The Supervisor may review/merge this research workstream and, after the mandatory latest-main broadcast/synchronization, admit the dependency-ready SES, Brevo, Gmail and contract-matrix lanes.
