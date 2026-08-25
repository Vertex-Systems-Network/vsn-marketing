# TASK-0013 Research Pack

- researched_at: 2026-08-25T04:43:21+05:00
- task: TASK-0013
- phase: PHASE-03
- scope: Provider/channel abstraction, authentication/readiness, quota/rate-limit semantics, webhook verification, social publishing constraints, market workflow expectations, security/supply-chain baselines, and implications for the PHASE-03 task chain.
- researcher: OpenAI development AI using current official/primary internet sources plus canonical repository evidence

## Sources

| Source | Type | Version/date | Accessed | Why authoritative |
|---|---|---|---|---|
| https://docs.aws.amazon.com/ses/latest/dg/quotas.html | AWS official docs | current | 2026-08-25 | Canonical SES quotas, recipient accounting, sandbox and message-size constraints |
| https://docs.aws.amazon.com/ses/latest/dg/manage-sending-quotas.html | AWS official docs | current | 2026-08-25 | Rolling 24-hour quota and regional behavior |
| https://docs.aws.amazon.com/ses/latest/APIReference-V2/API_SendQuota.html | AWS API reference | SES v2 | 2026-08-25 | Machine-visible quota fields |
| https://developers.brevo.com/docs/oauth | Brevo official docs | current | 2026-08-25 | OAuth lifecycle, scopes and current private-app restriction |
| https://developers.brevo.com/docs/api-limits | Brevo official docs | current | 2026-08-25 | Endpoint/tier-specific RPS and RPH limits |
| https://developers.brevo.com/docs/limit-headers | Brevo official docs | current | 2026-08-25 | Dynamic quota headers and retry guidance |
| https://developers.google.com/workspace/gmail/api/reference/quota | Google official docs | May 1 2026 quota model | 2026-08-25 | Gmail quota units, per-project/per-user windows and recipient limit |
| https://developers.google.com/workspace/gmail/api/auth/scopes | Google official docs | current | 2026-08-25 | Sensitive/restricted scopes and verification/security-assessment implications |
| https://developers.tiktok.com/docs/en/content-posting-api-reference-direct-post | TikTok official docs | updated Aug 4 2026 | 2026-08-25 | Direct Post flow, scope, per-token rate, async status, AI-generated-content metadata |
| https://developers.tiktok.com/docs/en/content-posting-api-get-started | TikTok official docs | updated Aug 4 2026 | 2026-08-25 | App approval/audit, creator-info and private-only unaudited behavior |
| https://developers.tiktok.com/docs/en/content-sharing-guidelines | TikTok official policy/docs | updated Aug 4 2026 | 2026-08-25 | Required UX and content-sharing restrictions |
| https://learn.microsoft.com/en-us/linkedin/marketing/community-management/shares/posts-api | LinkedIn official docs | versioned REST API | 2026-08-25 | Version headers, role/scope restrictions and content-mode differences |
| https://developers.google.com/youtube/v3/revision_history | Google/YouTube official docs | Jun 2026 granular quota transition | 2026-08-25 | Quota model changes and audit/private-upload restriction history |
| https://developers.pinterest.com/docs/reference/rate-limits/ | Pinterest official docs | current | 2026-08-25 | Trial/standard access tiers and category-specific rate limits |
| https://developers.pinterest.com/docs/developer-tools/sandbox/ | Pinterest official docs | current | 2026-08-25 | Sandbox separation and unsupported sandbox operations |
| https://developers.pinterest.com/docs/api/v5/pins-create/ | Pinterest official API reference | v5 | 2026-08-25 | Pin publishing, auth, rate category and sandbox support |
| https://csrc.nist.gov/pubs/sp/800/218/final | NIST | SSDF 1.1 final | 2026-08-25 | Current final secure-development baseline |
| https://csrc.nist.gov/pubs/sp/800/218/r1/ipd | NIST | SSDF 1.2 initial public draft, Dec 17 2025 | 2026-08-25 | Forward-looking draft; explicitly not final normative baseline |
| https://owasp.org/www-project-artificial-intelligence-security-verification-standard-aisvs-docs/ | OWASP | AISVS 1.0, Jun 24 2026 | 2026-08-25 | Current testable AI-system security requirements |
| https://slsa.dev/spec/v1.2/ | SLSA official spec | v1.2 | 2026-08-25 | Build provenance and hardened build-system model |
| https://docs.github.com/en/actions/security-for-github-actions/security-guides/security-hardening-for-github-actions | GitHub official docs | current | 2026-08-25 | Immutable full-length SHA pinning and Actions hardening guidance |
| https://www.w3.org/TR/WCAG22/ | W3C Recommendation | WCAG 2.2 | 2026-08-25 | Current accessibility target for first-party UI |
| HubSpot current social-management / Social Media AI Agent product documentation | Official product reference | current | 2026-08-25 | Market workflow benchmark: CRM-linked social publishing, governance and AI assistance |
| Klaviyo Social Marketing / Composer product documentation | Official product reference | Jul 2026/current | 2026-08-25 | Market benchmark: social signals feeding CRM/journeys and human-approved AI campaign assembly |
| Customer.io journeys / WhatsApp documentation | Official product reference | current, WhatsApp docs updated Aug 2026 | 2026-08-25 | Market benchmark: inbound channel events normalized into automation/segments |
| Buffer channel/API/AI documentation | Official product reference | current | 2026-08-25 | Market benchmark: per-channel capability matrix, publishing validation and AI disclosure handling |

## Current external reality

### Provider capability is multi-dimensional, not boolean

Current APIs invalidate a simple `supports_email`, `supports_social`, or `supports_webhooks` model:

- AWS SES quotas are regional, rolling and recipient-based; sandbox/production readiness also differs per region.
- Brevo applies endpoint/tier-specific RPS and RPH simultaneously and exposes dynamic remaining/reset headers.
- Gmail uses abstract quota units across per-project and per-user windows, and the model changed on May 1, 2026.
- TikTok can technically accept Direct Post requests while the client remains unaudited and therefore private-only; user consent, approved scopes and creator-specific privacy options are runtime prerequisites.
- LinkedIn operation availability depends on scope, member/company-page role, content type and API version header.
- YouTube is actively moving to granular quota buckets; hard-coded historical quota-unit assumptions are already stale.
- Pinterest distinguishes trial/standard access, category quotas and a separate sandbox with its own tokens and limitations.

Therefore VSN needs capability metadata plus readiness/eligibility metadata, not a single capability flag.

### Authentication is a lifecycle

A `ProviderConnection` must represent more than the existence of credentials. Research shows the need to model:

- auth scheme: API key, OAuth2, AWS signature/IAM, SMTP credentials, provider-specific schemes;
- delegated versus application/service identity where relevant;
- token expiry, refresh, rotation and revocation;
- requested/granted scopes and provider roles;
- provider/app review or verification state;
- sandbox/trial/private-only versus production/public eligibility;
- region/account/project/user scope;
- secret references rather than credential material;
- degraded/revoked/deprecated states.

Example readiness states should include `configuration_required`, `auth_required`, `scope_required`, `provider_review_required`, `sandbox_only`, `private_only`, `active`, `degraded`, `revoked`, `deprecated`, and `unavailable`. Exact enums remain implementation work for TASK-0015.

### Quota/rate-limit model

`ProviderQuota` must support multiple concurrent dimensions:

- metric/unit: request, recipient, message, object, quota-unit, byte, upload, event;
- window: second/minute/hour/day/rolling-24h/provider-defined;
- scope: provider account, project, user, token, workspace connection, endpoint/category, region;
- tier/access mode: sandbox/trial/standard/enterprise/provider-specific;
- static versus runtime-discovered values;
- current usage, remaining capacity and reset time when exposed;
- provider response headers or discovery API as evidence;
- provenance/accessed-at/freshness because limits change.

A single integer quota or one `requests_per_second` field is insufficient.

### Webhooks require verifier strategies

Different providers expose different authenticity and replay mechanisms. VSN must model webhook verification as a connector-owned strategy rather than assuming universal HMAC semantics. Contracts must be able to express:

- raw-body preservation before parsing;
- signature/HMAC plus optional timestamp window;
- provider-specific signature schemes;
- bearer/basic/custom-header verification where documented;
- IP allowlist as an additional control where documented, never the only assumed global mechanism;
- endpoint verification/challenge flows;
- replay/duplicate detection, batch identity and event ordering metadata;
- idempotent canonical event normalization;
- explicit `unsupported` rather than silently unverified webhooks.

### Publishing APIs have policy-level readiness

Future social publishing contracts must carry enough metadata to represent:

- account/profile types and provider roles;
- app/scopes/review/audit status;
- direct publish versus draft/upload versus remote scheduling;
- supported content/media types and provider limits;
- provider-generated upload URLs and expiry;
- async publish/status polling;
- update/delete/cancel behavior where supported;
- comment/community/analytics capabilities;
- visibility/privacy choices;
- AI-generated-content disclosure metadata;
- brand/paid-partnership metadata;
- provider-required UX/consent steps;
- policy limitations and source provenance.

TikTok's Aug 2026 Direct Post documentation is a concrete example: approved `video.publish`, explicit consent, creator-info-derived options, a six-requests/minute access-token limit, audit-dependent public visibility, async publish ID/status, and `is_aigc` metadata all affect whether an action is actually executable.

## Market/reference workflow

Current mature systems reinforce VSN's broader Marketing OS direction:

- HubSpot combines social publishing/monitoring with CRM identity, campaigns, permissions and AI-assisted content/timing.
- Klaviyo's 2026 social direction treats comments/DMs/social signals as customer-profile and journey inputs rather than an isolated scheduler; its AI campaign composition still requires marketer sign-off.
- Customer.io normalizes channel events such as inbound WhatsApp into segmentation and automation triggers.
- Buffer exposes a channel capability matrix and validates channel-specific publishing/AI-disclosure combinations instead of pretending every network behaves identically.

This supports a canonical flow:

`Customer/Event data -> Campaign/Journey/AI proposals -> Assets/Content -> Publishing/Delivery -> provider adapter -> normalized engagement/events -> Analytics/Attribution -> bounded optimization`

It does not justify copying proprietary product behavior or moving PHASE-13 social adapters into PHASE-03.

## Security/privacy findings

1. NIST SSDF 1.1 remains the final normative baseline. SSDF 1.2 is an initial public draft; VSN should monitor its delta but must not silently treat a draft as a mandatory final standard.
2. OWASP AISVS 1.0 became available June 24, 2026 and provides testable AI-system security requirements. It should inform PHASE-10 AI-security acceptance/evals and TASK-0014 threat-model/security planning where AI-enabled development or product surfaces are affected.
3. SLSA 1.2 supports a progressive build provenance/hardening model; VSN should define a practical target rather than merely saying "use SLSA".
4. GitHub's current Actions hardening guidance states a full-length commit SHA is the immutable pinning mechanism for third-party actions. Current repository workflows use movable major tags and need TASK-0014 remediation or documented exception.
5. GitHub CodeQL does not provide PHP analysis. VSN should use CodeQL for supported JavaScript/TypeScript/GitHub Actions surfaces and a PHP-capable SAST/taint path (for example a measured Psalm-taint and/or Semgrep strategy) instead of falsely claiming complete PHP coverage from CodeQL.
6. OAuth/restricted-scope providers can impose verification or security-assessment obligations. Provider readiness metadata must fail closed if the connection is technically authenticated but not authorized for the requested production operation.
7. Provider/customer PII, tokens, mailbox content, social DMs and analytics expand data-classification/retention/redaction responsibilities; later tasks must research channel-specific privacy before collection/processing.

## API/platform constraints

### Reference-provider selection for PHASE-03

Research recommends the following **reference connector families for TASK-0017**, subject to fresh revalidation when TASK-0017 activates:

- **Amazon SES** — delivery reference. Exercises regional readiness, recipient-based rolling quotas, AWS/IAM and distinct SMTP credential patterns.
- **Brevo** — delivery/marketing-platform reference. Exercises API-key + OAuth lifecycle, granular scopes, private-app readiness, multi-window endpoint rate limits and webhooks.
- **Gmail API** — mailbox reference. Exercises restricted/sensitive OAuth scopes, per-user/per-project quota units and mailbox-style operations that must not be treated as bulk-mail delivery.

This trio gives higher architectural diversity than implementing several similar transactional-email APIs first. Other catalog providers remain planned. Microsoft Graph Mail is a strong later mailbox reference and should remain preplanned, but is not required to prove the first PHASE-03 abstraction if the above three satisfy the contract matrix.

### Social/reference providers

TikTok, LinkedIn, YouTube and Pinterest were researched in TASK-0013 specifically to prove that the abstraction is channel-neutral. Their concrete adapters remain PHASE-13 unless a later approved prerequisite changes sequencing.

## Performance/reliability findings

- Runtime rate-limit information must be consumable by later throttling/routing logic; static configuration alone will drift.
- External operations that return asynchronous IDs/status need reconciliation instead of assuming HTTP success equals business completion.
- Provider 429/reset behavior must become normalized retry/backoff signals.
- Upload URLs/tokens can expire independently of the main auth token; publication/media workflows need bounded retry and re-initialization semantics.
- Provider outage/failover may move traffic into a region/account with a different quota or readiness state; fallback may never bypass consent, authorization, approval, region, budget or risk policy.
- Webhook duplicates, replay, delay and out-of-order delivery must be expected and normalized into idempotent canonical event handling.
- PHASE-03 should define contracts and certification matrices; measured routing/load/failover SLO implementation belongs to PHASE-04.

## Conflicts with current assumptions

| Finding | Classification | Reconciliation |
|---|---|---|
| Existing capability namespace is email/mailbox-heavy and cannot describe social publishing/community semantics | ADR_REQUIRED / NEW_ACCEPTANCE_CRITERION | Accept ADR-0002; extend capability taxonomy without implementing social adapters |
| Existing integration standard treats auth/quota/webhooks too coarsely | NEW_ACCEPTANCE_CRITERION | Strengthen provider-manifest standard with lifecycle/readiness/quota/verifier/version/provenance requirements |
| `tools/ai_context.py` discovers ADRs from `.ai/decisions`, but canonical ADRs are in `.ai/adr` | NEW_PREREQUISITE | Fix context compiler and regression tests inside TASK-0013 |
| New governance/research/quality/preplan files are omitted from deterministic context base | NEW_PREREQUISITE | Add canonical governance sources and active research pack to context inventory |
| No PHASE-01 roadmap document exists even though PHASE-01 is completed | NEW_ACCEPTANCE_CRITERION | Add explicitly retrospective traceability record derived only from TASK-0003..0007; do not fabricate original planning evidence |
| Generic "CodeQL" would not cover PHP | NEW_ACCEPTANCE_CRITERION | TASK-0014 must split supported CodeQL surfaces from a PHP-capable SAST/taint path |
| Historical fixed API quotas can become stale within months | CONFIRMS_PLAN | Keep volatile provider limits refreshable, provenance-stamped and revalidated at task activation |
| Social platform technical access does not imply production eligibility | CONFIRMS_PLAN / NEW_ACCEPTANCE_CRITERION | ProviderConnection/readiness model must include app-review/audit/tier/sandbox/private-only state |
| A single webhook-signature mechanism is not portable | NEW_ACCEPTANCE_CRITERION | Connector-owned webhook verifier strategy with explicit unsupported behavior |

## Required roadmap extensions

No phase renumbering is required. The existing TASK-0013..TASK-0100 skeleton is directionally sound. Research requires the following explicit refinements:

1. TASK-0014: repository rules/status enforcement evidence; dependency/secret/container scanning; CodeQL only on supported JS/TS/Actions surfaces; PHP-capable SAST/taint scanning; immutable action SHA pinning; SBOM; practical SLSA provenance target; exceptions with owner/expiry.
2. TASK-0015: multidimensional quota model; connection readiness/auth lifecycle; scopes/roles/provider-review/access-tier/region metadata; secret-reference lifecycle; tenant isolation.
3. TASK-0016: adapter capability schema; normalized errors; strategy-driven webhook verification; raw-body/replay/idempotency; async reconciliation; version/deprecation/provenance; rate-limit discovery contracts.
4. TASK-0017: initial reference connectors selected as SES + Brevo + Gmail unless fresh TASK-0017 research changes the choice; provider contract/sandbox/security matrix mandatory.
5. TASK-0018: exact-head certification must prove provider neutrality, cross-workspace isolation, unsupported-capability failure, security controls and no PHASE-04+ implementation pull-forward.
6. Future social tasks must revalidate network APIs at activation because quota, app-review, media and disclosure behavior is volatile.

These are refinements of already reserved tasks, not hidden new roadmap work.

## Rejected options

### Provider-name branching in core
Rejected. Current provider differences are too broad and volatile; capabilities/readiness must carry the differences behind adapters.

### One flat `ProviderQuota` integer
Rejected. SES, Brevo, Gmail, YouTube and Pinterest demonstrate simultaneous windows, scopes, units, tiers and dynamic discovery.

### Generic `supports_social` / `supports_webhook_signature`
Rejected. These erase operationally important restrictions and can create unsafe false positives.

### Implement social connectors during PHASE-03
Rejected. Research is needed to shape the abstraction, but concrete social/community implementation belongs to PHASE-13 under the current approved sequence.

### Select many near-identical email providers as PHASE-03 references
Rejected. A small diverse reference set provides stronger abstraction evidence with less premature implementation surface.

### Treat NIST SSDF 1.2 draft as final policy
Rejected. SSDF 1.1 remains final; 1.2 is monitored as a draft until NIST publishes a final revision.

## Decision impact

- `ADR_REQUIRED`: accept ADR-0002 after incorporating this evidence because Research-First governance and the Assets/Publishing/Community boundaries are supported by current platform/market evidence and do not duplicate existing modules.
- `CONFIRMS_PLAN`: retain the existing PHASE-00..PHASE-16 sequence and TASK-0013..TASK-0100 minimum skeleton.
- `NEW_ACCEPTANCE_CRITERION`: materialize researched TASK-0014..TASK-0018 machine tasks with the refinements above.
- `NEW_PREREQUISITE`: repair deterministic context inventory and PHASE-01 traceability before TASK-0013 can complete.
- `NO_PRODUCT_IMPACT`: no provider SDK or product-channel implementation is authorized by this research pack.

## Freshness risks

The following claims are especially volatile and MUST be revalidated when their implementation task activates:

- provider quotas/rate limits and billing thresholds;
- OAuth scopes/token lifetime/app-distribution policy;
- social network app-review/audit requirements;
- media limits/content types;
- AI-generated-content and paid-partnership metadata requirements;
- API versions/deprecation/sunset dates;
- sandbox/trial/public-visibility restrictions;
- security standards still in draft status;
- provider privacy/data-processing terms and regional availability.

Repository planning must store implementation impact and source provenance, not freeze today's volatile values as permanent domain truth.
