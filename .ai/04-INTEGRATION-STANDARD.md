# Integration Standard

## Adapter-first rule

Every external provider implements canonical capability contracts. No core/application module may contain provider-name conditionals for behavior that belongs in an adapter, provider manifest, capability check, readiness check, quota policy, or webhook verifier.

A provider can expose a technical feature while a particular connection remains ineligible to execute it. Therefore **capability** and **readiness** are separate concepts.

Unknown, stale, unverified, or unsupported metadata fails closed.

## Provider manifest

Every connector manifest declares enough information for deterministic software to decide what the connector can do and what prerequisites still apply.

### Identity and source provenance

- provider ID and connector version;
- provider class or classes;
- API/protocol version strategy;
- official documentation/source URLs;
- researched/verified timestamp for volatile metadata;
- deprecation/sunset notices when known;
- known limitations and unsupported operations.

### Authentication and connection lifecycle

Declare applicable:

- authentication schemes such as API key, OAuth2, signed request/IAM, SMTP credentials, service/application identity, or provider-specific methods;
- delegated versus application/service authorization where relevant;
- token expiry, refresh, rotation and revocation behavior;
- requested/required/granted scopes;
- provider roles/account types/profile types required by an operation;
- provider/app review, verification, audit, marketplace or production-access requirements;
- region/project/account/user/token scope;
- secret-reference fields required by the connector;
- reauthorization/rotation triggers.

Credential material never belongs in the manifest or canonical application data; only secret references/metadata do.

### Readiness

The manifest defines the readiness facts the connector can evaluate. Connection/application code may normalize them into states such as:

- `configuration_required`;
- `auth_required`;
- `scope_required`;
- `provider_review_required`;
- `sandbox_only`;
- `private_only`;
- `active`;
- `degraded`;
- `revoked`;
- `deprecated`;
- `unavailable`.

Exact executable enums belong to TASK-0015, but the framework must never infer production eligibility merely from a successful authentication handshake.

### Capabilities

A connector advertises only operations proven by implementation plus contract tests. Capabilities use the canonical namespace in `.ai/integrations/CAPABILITY-MATRIX.yaml` and may carry provider constraints such as supported media/content variants or required scopes.

Callers must explicitly handle unsupported capability results; they must not guess from provider identity.

### Rate limits and quotas

Rate/quota declarations and discovery must be able to describe multiple simultaneous constraints:

- metric/unit: request, recipient, message, quota unit, object, event, byte/upload, provider-defined unit;
- window: second, minute, hour, day, rolling 24 hours, provider-defined;
- scope: provider account, connection, project, user, access token, endpoint/category, region, workspace/provider mapping;
- tier/environment: sandbox, trial, standard, enterprise, provider-defined;
- static configured values versus runtime-discovered values;
- current usage, remaining capacity and reset time where available;
- discovery endpoint/headers and provenance timestamp.

A single `requests_per_second` or `daily_limit` field is never assumed to describe the full provider quota model.

### Environments and sandbox behavior

Declare applicable production, sandbox, trial, test, private-only, unaudited/unverified or other provider environments, including:

- credentials/token separation;
- visibility restrictions;
- unsupported sandbox operations;
- test-recipient/test-account requirements;
- production-review/approval prerequisites.

### Webhooks and events

For every supported webhook surface declare:

- event types and mapping responsibility;
- authenticity/verifier strategy;
- whether raw request bytes are required for verification;
- signature/hash/timestamp scheme where applicable;
- endpoint challenge/verification behavior;
- bearer/basic/custom-header or other documented controls where applicable;
- optional IP allowlist support where documented;
- replay/duplicate characteristics and available provider event IDs;
- batch/order semantics when known;
- retry/redelivery behavior when known;
- source documentation/version.

Do not expose a generic `supports_webhook_signature=true` as if all providers share one mechanism. `unsupported` must be explicit when authenticity cannot be verified to the required policy level.

### Publication/media constraints

For publishing-capable providers, manifests must be able to declare applicable:

- account/profile types and provider roles;
- direct publish, draft/upload, remote schedule and status-read modes;
- text/image/video/carousel/document or other media/content support;
- payload/media/duration/count constraints;
- upload initiation/chunking/expiring-upload behavior;
- async publication IDs and reconciliation/status polling;
- supported update/delete/cancel semantics;
- privacy/visibility options;
- AI-generated-content disclosure metadata;
- paid-partnership/brand metadata;
- provider-required user-consent/UX steps;
- comments/community/DM/analytics support.

These fields make PHASE-03 channel-neutral. They do not authorize concrete social connector implementation before its active task.

### Data, region, policy and compliance metadata

Declare known provider facts relevant to deterministic policy, including region/data-processing restrictions, account/tenant boundaries, anti-abuse requirements, retention constraints exposed by the integration, and any required user/provider approval.

Legal/compliance claims are not inferred from a provider capability flag and must be researched for the relevant jurisdiction/task.

## Required connector components

```text
connectors/<provider>/
  manifest
  auth
  client
  mapper
  capabilities
  quotas
  templates (when supported)
  webhooks
  errors
  tests
  README
  CHANGELOG
```

A component can be intentionally absent only when the manifest declares the feature unsupported and contract tests prove callers fail safely.

## Canonical behavior

- Normalize provider errors into stable error categories while retaining provider evidence/reference IDs.
- Normalize provider events into canonical events.
- Expose capability and readiness rather than making callers infer them.
- Respect provider terms, quotas, rate limits and anti-abuse policies.
- Store credentials through a secret-reference abstraction.
- Verify webhook authenticity through the provider/surface strategy before trusting the payload.
- Preserve raw webhook bytes when the verifier requires them.
- Retries must be idempotent and classify retryable versus permanent failure.
- HTTP/API acceptance does not automatically equal business completion; asynchronous operations require status/reconciliation contracts.
- Provider fallback never weakens consent, suppression, authorization, approval, region, budget, schema or risk policy.
- Volatile provider limits/policies carry source provenance and are revalidated by the Research-First Gate before implementation.

## Versioning and deprecation

A connector must make API/protocol version expectations observable. Provider deprecation/sunset information is treated as operational metadata and a compatibility risk, not buried in comments.

Breaking provider-version changes require contract-test evidence and, when they change canonical architecture, an ADR rather than a provider-specific exception in core code.

## Connector certification

Before activation, connector tests cover applicable:

- auth success/failure/expiry/refresh/revocation;
- missing/insufficient scopes and roles;
- sandbox/private/provider-review readiness;
- declared versus unsupported capabilities;
- success path;
- transient/permanent errors;
- concurrent rate/quota constraints and 429/reset behavior;
- idempotency/retry behavior;
- malformed provider responses;
- webhook authenticity, malformed payload, replay/duplicate behavior;
- async status/reconciliation;
- provider API version/deprecation metadata;
- cross-workspace connection/reference isolation.

Social/publishing connectors additionally test account types, app review, media constraints, direct/draft modes, status polling, supported edit/delete, community permissions, analytics availability and required AI/partnership disclosure metadata.

## Future providers

A future API must be attachable by implementing these contracts and passing connector certification. Core domain/application modules must not require modification merely to recognize the provider name.
