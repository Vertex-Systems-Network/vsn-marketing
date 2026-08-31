# TASK-0015 Research Pack

- researched_at: 2026-08-31T13:22:10+05:00
- task: TASK-0015
- phase: PHASE-03
- scope: Canonical provider catalog/connection/capability/quota foundation, authentication lifecycle, connection readiness, secret-reference handling, quota dimensionality, provenance/freshness and tenant isolation.
- researcher: OpenAI development AI using current official/primary internet sources plus canonical repository evidence
- conclusion: CONFIRMS_PLAN

## Sources

| Source | Type | Version/date | Accessed | Why authoritative |
|---|---|---|---|---|
| https://docs.aws.amazon.com/ses/latest/dg/quotas.html | AWS official docs | current | 2026-08-31 | SES region-scoped sending quota and sandbox limits |
| https://docs.aws.amazon.com/ses/latest/dg/manage-sending-quotas.html | AWS official docs | current | 2026-08-31 | Rolling 24-hour recipient-based quota behavior |
| https://docs.aws.amazon.com/ses/latest/APIReference-V2/API_SendQuota.html | AWS API reference | current | 2026-08-31 | Runtime quota fields and usage discovery |
| https://developers.brevo.com/docs/authentication-schemes | Brevo official docs | current | 2026-08-31 | API-key and OAuth authentication families |
| https://developers.brevo.com/docs/oauth | Brevo official docs | current | 2026-08-31 | OAuth scopes, token/refresh lifecycle and private-only app readiness |
| https://developers.brevo.com/docs/limit-headers | Brevo official docs | current | 2026-08-31 | Runtime rate-limit limit/remaining/reset evidence |
| https://developers.google.com/workspace/gmail/api/reference/quota | Google official docs | May 1 2026 quota model | 2026-08-31 | Per-project/per-user quota-unit dimensions and method costs |
| https://developers.google.com/workspace/gmail/api/auth/scopes | Google official docs | current | 2026-08-31 | Sensitive/restricted scopes and verification/security-assessment requirements |
| https://developers.tiktok.com/docs/en/content-posting-api-get-started | TikTok official docs | Aug 2026/current | 2026-08-31 | Scope approval and audit-dependent private-only readiness |
| https://developers.tiktok.com/docs/en/content-sharing-guidelines | TikTok official policy/docs | Aug 2026/current | 2026-08-31 | Unaudited-user/private-view and creator/post-cap constraints |
| .ai/03-DATA-MODEL.md | Canonical repository contract | main a90e17c | 2026-08-31 | Workspace ownership and canonical Provider entities |
| .ai/04-INTEGRATION-STANDARD.md | Canonical repository contract | main a90e17c | 2026-08-31 | Provider-neutral capability/readiness/auth/quota contract |
| .ai/11-RESEARCH-FIRST-STANDARD.md | Canonical repository contract | main a90e17c | 2026-08-31 | Mandatory research/revalidation lifecycle |
| .ai/adr/ADR-0002-research-first-preplanned-marketing-os.md | Accepted architecture decision | ADR-0002 | 2026-08-31 | Research-first, channel-neutral and refreshable-provider-metadata constraints |
| .ai/research/PHASE-03/TASK-0013-RESEARCH.md | Prior primary research pack | researched 2026-08-25 | 2026-08-31 | Baseline provider/auth/readiness/quota findings being revalidated |

## Revalidation findings

### Capability and readiness remain separate

Technical support is not sufficient evidence that an operation is executable. TikTok still restricts content from unaudited clients to private viewing, Brevo OAuth apps are currently private-only within the user's Brevo organisation, and Gmail scopes can require verification/security assessment. TASK-0015 therefore needs a connection readiness state independent from capability support.

The canonical readiness vocabulary must be able to represent at least configuration/auth/scope requirements, provider review, sandbox/private-only access, ready/active operation, suspension/revocation, degradation and deprecation/unavailability. Unknown or stale capability/readiness must fail closed rather than being treated as supported.

### Authentication is lifecycle metadata, not credential storage

Current providers still require materially different auth families: API keys, OAuth2 delegated access, signed IAM/service identity and SMTP/provider credentials. OAuth examples require requested/granted scopes, access-token expiry, refresh lifecycle, revocation/rotation and provider verification/access-mode information.

TASK-0015 must store only opaque secret references plus non-secret lifecycle metadata. Raw API keys, client secrets, access tokens, refresh tokens or passwords must never become canonical ProviderConnection data.

### Quotas are multidimensional and volatile

Fresh official documentation confirms a scalar quota is invalid:

- SES quotas are per AWS Region, recipient-based and rolling over 24 hours; sandbox defaults differ from production and runtime APIs expose current limits/usage.
- Brevo limits vary by endpoint and account plan and expose runtime limit/remaining/reset response headers.
- Gmail's May 1, 2026 model uses quota units with distinct per-project and per-user-per-project windows plus method-specific unit costs.
- TikTok access can include client/user/creator/post restrictions independent from ordinary request rate limits.

ProviderQuota therefore needs operation/metric/unit, window, region/scope/principal/tier/access-mode, static-vs-discovered semantics, optional limit/usage/remaining/reset data and source/version/observed/freshness provenance. Volatile numeric limits belong in refreshable data, not hard-coded domain constants.

### Workspace isolation is mandatory

The canonical data model says all tenant-owned records carry a workspace boundary. Existing repository migrations reinforce that boundary with composite `(id, workspace_id)` uniqueness/FKs and repository queries filter by workspace. TASK-0015 will apply the same fail-closed pattern to Provider, ProviderConnection, ProviderCapability and ProviderQuota, including references from capability/quota/credential metadata to a connection.

### Provider neutrality remains intact

No fresh source contradicts ADR-0002 or TASK-0015 acceptance criteria. Concrete SES, Brevo, Gmail, TikTok or other SDK/API calls are not required to prove this foundation and would violate sequencing. Provider-specific adapters remain later connector work.

## Reconciliation

| Finding | Classification | TASK-0015 action |
|---|---|---|
| Provider records are canonical tenant-owned concepts | CONFIRMS_PLAN | Make all four TASK-0015 entities workspace-bound and enforce composite isolation |
| Capability support does not imply operational eligibility | CONFIRMS_PLAN | Model support state separately from connection readiness |
| Auth schemes and tokens have lifecycle/scopes/review constraints | CONFIRMS_PLAN | Store auth family + non-secret metadata + opaque secret reference only |
| Provider limits are multi-window/multi-scope and dynamically discoverable | CONFIRMS_PLAN | Use multidimensional quota rows with runtime/provenance fields; no permanent fixed limits |
| OAuth/provider review can make technically connected accounts private/sandbox-only | CONFIRMS_PLAN | Preserve provider-review/access-tier/environment/region readiness metadata |
| Existing Providers module/data-model vocabulary already covers this work | CONFIRMS_PLAN | No ADR, phase change or module rename required |

## Implementation boundary

TASK-0015 may now implement:

1. a `Providers` module with canonical domain records/contracts and database repositories;
2. rollback-safe migrations for workspace-scoped Provider, ProviderConnection, ProviderCapability and ProviderQuota data;
3. explicit support/readiness/auth/discovery semantics with opaque secret references;
4. source/version/observed/freshness provenance on volatile provider metadata;
5. feature/integration/architecture tests proving fail-closed tenant isolation and absence of provider SDK coupling.

TASK-0015 must not implement concrete provider SDKs, outbound delivery, webhook adapters, routing, connector-specific retry logic or TASK-0016+ behavior.

## Research gate result

`PASS — CONFIRMS_PLAN`.

The 2026-08-25 TASK-0013 architecture remains valid after fresh 2026-08-31 revalidation. No new prerequisite, ADR or acceptance criterion is required before TASK-0015 implementation.