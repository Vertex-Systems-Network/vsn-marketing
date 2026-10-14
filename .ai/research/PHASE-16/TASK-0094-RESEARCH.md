# TASK-0094 — Enterprise readiness research (working draft)

- researched_at: 2026-10-13T16:50:00Z
- task: TASK-0094
- phase: PHASE-16
- researcher: VSN Marketing Supervisor
- Scope: PHASE-16, TASK-0094 through TASK-0100
- Status: LOCAL RESEARCH; not accepted, not a production certification

## Primary sources and decisions

| Source | Boundary for implementation |
| --- | --- |
| NIST SP 800-63-4, https://pages.nist.gov/800-63-4/ | Define identity assurance and federation policy by risk; do not claim a particular assurance level merely because SSO exists. Session and authenticator requirements need separate evidence. |
| RFC 7644, https://www.rfc-editor.org/rfc/rfc7644.html | Model SCIM provisioning with tenant-bound bearer authority, stable resource identifiers, PATCH/filter handling, replay protection and deterministic deprovisioning. Provisioning must not silently elevate roles. |
| RFC 9865, https://www.rfc-editor.org/rfc/rfc9865.html | Optional cursor pagination update to SCIM query behavior; account for it when a listing adapter is selected. It does not make the current offline gate interoperable. |
| RFC 9967, https://www.rfc-editor.org/rfc/rfc9967.html | Optional asynchronous SCIM security-event profile; do not infer that the current synchronous prototype supports SET events. |
| WCAG 2.2, https://www.w3.org/TR/WCAG22/ | Target testable AA success criteria for enterprise admin, provisioning, billing and recovery flows; test keyboard, focus, labels, error recovery and status messaging. |
| EU GDPR, https://eur-lex.europa.eu/eli/reg/2016/679/oj/eng | Map access, erasure, portability, retention and international-transfer decisions to actual controller/processor roles and jurisdictions. Legal hold can restrict deletion; never claim universal erasure or residency. |
| NIST SP 800-34 Rev. 1, https://csrc.nist.gov/pubs/sp/800/34/r1/final | Document recovery objectives from an approved impact analysis and prove restore exercises; backups alone cannot establish RPO/RTO. |
| OpenID Connect Core 1.0, https://openid.net/specs/openid-connect-core-1_0.html | Check issuer, audience, nonce, expiry, signature and tenant binding for each federation assertion; federation does not determine workspace authorization. |
| OASIS SAML 2.0 Technical Overview, https://docs.oasis-open.org/security/saml/Post2.0/sstc-saml-tech-overview-2.0.html | If SAML is selected, validate signed assertions, recipient, in-response-to and replay cache; do not treat XML attributes as trusted local roles. |
| NIST SP 800-53 Rev. 5, https://csrc.nist.gov/pubs/sp/800/53/r5/upd1/final | Use access control, audit/accountability, privacy and contingency families as control mapping, without making a compliance certification claim. |
| PCI SSC document library, https://www.pcisecuritystandards.org/document_library/ | PCI DSS v4.0.1 is listed. Minimize cardholder-data scope through a hosted provider flow; determine actual applicability before making PCI claims. |
| Microsoft Entra provisioning, https://learn.microsoft.com/en-us/entra/identity/app-provisioning/how-provisioning-works | Provisioning spans create, maintenance and removal; demonstrate mapping and deprovisioning in a provider-specific integration test before a compatibility claim. |
| Okta SCIM concepts, https://developer.okta.com/docs/concepts/scim/ | Deactivation sets the user inactive; later deletion of an already deactivated Okta profile does not necessarily send a separate app-side DELETE. Treat active=false as an access-revocation event, not merely a display field. |
| SCIM core schema RFC 7643, https://www.rfc-editor.org/rfc/rfc7643.html | Preserve stable resource IDs and explicit active state; do not use mutable email as an authoritative user key. |
| EDPB Recommendations 01/2020, https://www.edpb.europa.eu/documents/recommendation/recommendations-012020-on-measures-that-supplement-transfer-tools-to_en | Cross-border processing must be mapped to transfer mechanisms and supplementary measures; a UI residency selector cannot establish transfer compliance. |
| Stripe webhook and idempotency documentation, https://docs.stripe.com/webhooks and https://docs.stripe.com/api/idempotent_requests | Illustrative provider behavior only: payment outcomes can be asynchronous and write retries use idempotency keys. Stripe is not selected as a VSN provider; recheck any chosen adapter's actual event ordering, signatures, retries and retention. |

Sources above were checked against their publishers on the research date. The NIST identity suite superseded SP 800-63-3 in 2025; older baseline statements must not be reused as current requirements.

## Current external reality and reference workflow

OIDC and SAML carry authentication assertions, while SCIM is a separate provisioning protocol. An enterprise administrator typically configures federation, performs a test login, previews user/group mapping, limits administrative roles, tests deprovisioning, then monitors audit and exports. VSN must retain local authorization as a separate deterministic decision even when an IdP authenticates the user. Do not accept domain ownership or an email string alone as proof of tenant membership. Reject ambiguous account linking; record a redacted administrative decision. No particular provider, IdP, payment processor or cloud region has been selected or verified.

The current code has `Identity` tenancy and workspace authorization modules, `Audit` recording, and a `billing.manage` permission, but no SCIM/OIDC/SAML implementation or live billing contract found in the local module inventory. This is a source scan, not a production environment audit. The intended product workflow needs tenant policy preview, explicit disabled/pending/failed states, and operator-visible deprovisioning results.

Local implementation finding: `workspace_memberships` and role grants are persisted separately; `WorkspaceAuthorizer` joins both on the workspace, so membership removal would block permission checks. Laravel web sessions currently use Redis by default and do not have a user session index in this repo. A stale authenticated session must not retain tenant resource access; complete deprovisioning requires a durable account/workspace status check at every tenant entry and a deliberate session revocation strategy. The current `ScimProvisioningAdmission` domain class only performs offline scope checks and does not verify credentials, expose SCIM endpoints or deactivate members.

## Existing baseline and threat model

PHASE-15 is certified for offline autonomous marketing. It grants no production sending, provider credentials, payments or deployment rights. Reuse existing workspace/brand scoping, deterministic policy, canonical audit and authorization middleware. Before extending schema, inventory tenancy boundaries and existing identity, billing and audit abstractions.

- SSO: reject unsigned or wrong-audience assertions, stale replay, issuer confusion, tenant domain confusion and privilege changes from untrusted IdP attributes. Link an existing user only through a verified tenant policy; preserve emergency local administrative recovery with an audited, narrow procedure.
- SCIM: bind each token to one tenant and limited provisioning actions; exercise duplicate create, deactivation, group removal, stale PATCH and cross-tenant IDs under concurrent requests. Authentication sessions need revocation after deprovisioning.
- Residency/retention: enumerate primary database, queue, object storage, logs, backups, analytics and AI/provider egress. A region label is not evidence that every copy stays in-region. Legal holds and deletion requests need explicit precedence and immutable audit without retaining unnecessary personal data.
- Agency hierarchy: enforce delegated permissions at the target workspace and brand for every read/write/export; test sibling-client isolation and confused-deputy paths. White-label branding cannot change security-critical origin, consent or billing disclosure.
- Billing: keep metering, entitlements and plan changes deterministic and idempotent; reject forged usage, duplicate webhooks and model-initiated spend. Financial and tax claims require provider-specific and jurisdiction-specific review before activation.
- Procurement: collect operator-visible security controls, subprocessor/region inventory, audit export scope, incident/retention policy and restore evidence for customer review; do not claim a compliance certificate from mapping controls to a framework.
- DR: test restore from isolated encrypted backups, key availability, integrity and tenant separation; measure actual loss and time, including queues and external effect reconciliation. Rehearse release rollback; do not declare production recovery complete from synthetic fixtures.

## Dependency order and acceptance evidence

TASK-0094 research and approval precede TASK-0095 identity/governance; then TASK-0096 residency/retention, TASK-0097 agency hierarchy, TASK-0098 billing, TASK-0099 DR and TASK-0100 certification. Each implementation task requires negative-case tenant/auth tests and its own exact-head gates. Certification additionally needs independent security, privacy, accessibility, performance, reliability, supply-chain, restore-exercise and resulting-main evidence. Missing supported-region or provider evidence means the corresponding production claim stays disabled.

Classification: CONFIRMS_PLAN with stricter, testable boundaries; no architecture rebaseline or activation authority is granted by this draft.

## Platform constraints and rejected shortcuts

The standards do not select an IdP, prescribe a price plan, promise a residency region or provide a recovery SLA. Provider-specific scopes, webhook behavior, contractual data locations, encryption keys, payment tax handling and backup capabilities must be researched again when adapters are selected. Reject direct model-to-billing actions, automatically mapping IdP groups to owner privileges, shared SCIM tokens across tenants, region selection by UI label alone, and a backup-file existence check as DR certification.

## Performance and reliability evidence

Record bounded SCIM burst/rate tests, concurrent deprovision/login races, audit export pagination and restore size/time from the actual target stack. RPO and RTO remain unset until approved business impact analysis and measured restore exercises. A payment-provider outage, failed provisioning webhook or stale region route must fail closed without silently expanding access or spend.

## Research-to-plan reconciliation and freshness

`CONFIRMS_PLAN`: TASK-0094..0100 match the preplanned sequence. `NEW_ACCEPTANCE_CRITERION`: explicit session revocation after SCIM deactivation, sibling-client isolation, downstream-copy residency map, idempotent billing evidence and measured restore are recorded in staged task criteria. `BLOCKER` for any production claims: no selected provider/region/payment contract, approved RPO/RTO, legal interpretation or restore exercise. No task needs an ADR solely from this research; a new architecture choice must get a proposed ADR before coding. Revalidate source versions and selected provider APIs at each implementation gate. GDPR requirements depend on actual processing role, geography and data category; this document is a technical mapping, not legal advice.
