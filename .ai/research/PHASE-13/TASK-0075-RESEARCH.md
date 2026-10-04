# TASK-0075 Research Pack — channel and social API reality

- researched_at: 2026-10-04T00:45:00Z
- task: TASK-0075
- phase: PHASE-13
- scope: permission-based messaging, social publishing, Community, listening and cross-channel evidence
- researcher: Supervisor

## Sources

All sources below were retrieved on 2026-10-04. API versions, permissions and pricing must be rechecked immediately before an individual live adapter is implemented or activated.

| Source | Type | Current finding / implementation impact |
| --- | --- | --- |
| [Microsoft Azure SMS overview](https://learn.microsoft.com/en-us/azure/communication-services/concepts/sms/concepts), [messaging policy](https://learn.microsoft.com/en-us/azure/communication-services/concepts/sms/messaging-policy), [opt-out API](https://learn.microsoft.com/en-us/azure/communication-services/concepts/sms/opt-out-api-concept) | Official provider | Sender geography and opt-out rules vary; provider suppression cannot replace canonical suppression. No worldwide SMS promise. |
| [Meta WhatsApp Cloud API collection](https://www.postman.com/meta/whatsapp-business-platform/documentation/wlk6lh4/whatsapp-cloud-api) | Meta-owned official API collection | WABA/phone-number IDs, versioned Graph endpoints and template operations are distinct. Template approval and business/account access must be explicit. |
| [Google RCS agent creation](https://developers.google.com/business-communications/rcs-business-messaging/guides/build/agents), [launch](https://developers.google.com/business-communications/rcs-business-messaging/guides/launch), [API](https://developers.google.com/business-communications/rcs-business-messaging/reference/rest) | Official API/policy | Brand, region, use case and carrier launch approval constrain reach; recipient capability must be checked. No automatic SMS fallback or live launch. |
| [FCM HTTP v1](https://firebase.google.com/docs/cloud-messaging/send/v1-api), [Apple APNs server](https://developer.apple.com/documentation/usernotifications/setting-up-a-remote-notification-server) | Official push APIs | Project-scoped OAuth and device tokens, APNs provider/device credentials. App-owned opt-in and token lifecycle needed; a provider success is not proof of device display. |
| [LinkedIn Posts API](https://learn.microsoft.com/en-us/linkedin/marketing/community-management/shares/posts-api?view=li-lms-2026-05) | Official social API | Version header, organization permissions, publishing lifecycle and October 15 2026 sunset of Marketing version 202510. Pin supported versions per adapter and revalidate near sunset. |
| [TikTok direct post](https://developers.tiktok.com/docs/en/content-posting-api-get-started), [direct post reference](https://developers.tiktok.com/docs/en/content-posting-api-reference-direct-post) | Official social API | Creator metadata, explicit user consent, direct-post app configuration/audit and verified media URL constrain publication. Draft upload and direct post have different semantics. |
| [YouTube videos.insert](https://developers.google.com/youtube/v3/docs/videos/insert) | Official social API | Unverified projects created after July 2020 upload private-only; OAuth/quota/audit required for public publishing claims. |
| [Pinterest create pins](https://developers.pinterest.com/docs/work-with-organic-content-and-users/create-boards-and-pins/), [access tiers](https://developers.pinterest.com/docs/key-concepts/access-tiers/) | Official social API | Board ownership, OAuth scopes and access tier affect public visibility; sandbox-created pins cannot prove public publishing. |
| [Meta Instagram official API collection](https://www.postman.com/meta/instagram/documentation/6yqw8pt/instagram-api), [Facebook official API collection](https://www.postman.com/meta/facebook/documentation/r56bjfd/facebook-api), [Threads official API collection](https://www.postman.com/meta/threads/documentation/dht3nzz/threads-api) | Meta-owned API collections | Professional account/Page linkage, Page token, per-product scopes and app review are distinct. Threads has separate app/use case and permissions. Verify each endpoint against Meta product docs at implementation time. |
| [X developer platform](https://developer.x.com/) | Official platform | Current pricing is usage-based and mutable; do not run live read/write/listening on inferred budget or tier. Recheck endpoint policy and approved budget. |

## Current external reality and capability decisions

| Surface | Supported candidate | Constraint / honest capability |
| --- | --- | --- |
| SMS | Azure Communication Services behind canonical adapter | Geo/sender eligibility, opt-out, country policy and provider provisioning; synthetic contract first. |
| WhatsApp | Meta Cloud API template and reply paths | Approved business identity, template and conversation rules; never equate ordinary SMS consent with WhatsApp marketing permission. |
| RCS | Google RCS for Business | Verified launched agent, carrier/recipient capability, billing category and webhook lifecycle; no implicit reach. |
| Push | FCM/APNs for owned app | App-installed device and consent/token lifecycle; no invented mobile app integration. |
| In-app | First-party authenticated inbox | Scope/retention and delivery semantics within workspace; independent of external provider. |
| Instagram/Facebook/Threads | Professional/Page/Threads publishing candidates | Product-specific accounts, scopes, review, media state and publish status; no universal edit/delete guarantee. |
| LinkedIn | Organization/personal paths only when authorized | Versioned Posts API and organization roles/scopes; avoid old API assumptions. |
| TikTok | Creator-authorized direct post or draft | Distinct flows, audit and visible creator consent. |
| Pinterest/YouTube/X | Capability-gated candidates | Access tier, OAuth/quota/private-only uploads or spend controls. |
| Community/listening | Owned/account-authorized inbound and documented signals only | No open-ended social scraping or assumed DM/comment coverage; normalize provider IDs with tenant scope. |

## Market/reference workflow

Operators need a per-account capability view before composing, a visible approval and schedule state, a media/status preview, and an explicit unsupported state. Community assignments/moderation must preserve original channel/source and response approval. Cross-channel totals require metric definitions; a post view and a message delivery are different observations.

## Security/privacy findings

Separate channel-purpose consent and suppressions; independently validate provider opt-out. Credentials are references, scoped per tenant/account. Webhooks require signature verification, replay protection, idempotent receipt and scoped resource mapping. Unknown account, scope, ownership or platform state fails closed. Preserve retention/erasure and avoid putting message text or bearer tokens in general telemetry. AI drafts never publish or reply without deterministic authorization.

## API/platform constraints

No single social API offers every create/edit/delete/status/analytics/DM operation to every account. API app review, permission approval, OAuth grant, rate limit and account role are runtime capabilities, not code-completion claims. Provider status can be asynchronous; ambiguous network outcome must reconcile by provider reference before retry. A scheduled intent is not a live publication.

## Performance/reliability findings

Use bounded per-account/provider rate budgets, durable idempotent intents and backoff that honors provider limits. Monitor webhook lag, missing/replayed events, media processing and reconciliation, with no production throughput/SLO assertion absent representative measurements. Tenant and brand partitions must prevent one account consuming another account's budget.

## Conflicts with current assumptions

The preplan names broad platforms; it does not imply live credentials, granted scopes, app review, public visibility or complete endpoints. LinkedIn version sunset and X variable paid access make a timeless static adapter contract unsafe. Push also requires an owned installed app and token inventory. No conflict requiring an ADR to the canonical provider-neutral boundary was found.

## Required roadmap extensions / task mapping

- `CONFIRMS_PLAN`: TASK-0076 canonical capability matrix and guarded offline messaging contracts; per-provider research refresh before concrete adapter.
- `NEW_ACCEPTANCE_CRITERION`: TASK-0077 must expose account/scope/review/media/version/status differences and explicit unsupported operation state.
- `CONFIRMS_PLAN`: TASK-0078 Community isolation and approval, TASK-0079 only permitted signals, TASK-0080 source-specific metrics, TASK-0081 bounded certification.
- `BLOCKER` for **live activation only**: no provider account grant, app review, legal/brand authorization, verified mobile app/token inventory or approved billing is established by this research. Repository-side design/tests remain actionable.

## Rejected options

One generic `publish()` pretending all platforms support edit/delete/schedule; sending on a mere provider HTTP success; auto-fallback from RCS to SMS; search/scraping outside provider grants; shared credentials across tenants; using a personal login as a business Page authorization.

## Decision impact and freshness risks

Proceed with provider-neutral, capability-negotiated offline contracts and researched adapters in dependency order. Defer live sends/publication until explicit current grants and safety gates. Revalidate Meta Graph version/permissions, LinkedIn version, TikTok audit, Pinterest tiers, YouTube verification, X economics and regional SMS/RCS rules before each adapter and certification. This pack is architecture research, not a live integration acceptance test.
