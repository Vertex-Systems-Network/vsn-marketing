# VSN Marketing — Product-track architecture

| Field | Value |
|---|---|
| Version | 1.0 — 2026-10-17 |
| Owner task | TASK-0146 |
| Inputs | [SRS](SRS.md), [PRODUCT-TRACK-PLAN §2](../../.ai/roadmap/PRODUCT-TRACK-PLAN.md), [01-MASTER-ARCHITECTURE](../../.ai/01-MASTER-ARCHITECTURE.md), [03-DATA-MODEL](../../.ai/03-DATA-MODEL.md), [04-INTEGRATION-STANDARD](../../.ai/04-INTEGRATION-STANDARD.md) |
| Decisions | [ADR-0008](../../.ai/adr/ADR-0008-product-track-architecture.md) |
| API design | [API-V1.md](API-V1.md) |

## 1. System context (C4 level 1)

```mermaid
flowchart LR
  subgraph People
    B[Buyer team<br/>Owner/Admin/Marketer/Approver/Analyst/Viewer]
    S[Sellers<br/>Reseller · Vendor · Affiliate]
    P[Platform staff<br/>Super Admin · Support]
    C[Contacts / audience]
  end
  VSN[(VSN Marketing<br/>SaaS platform)]
  B --> VSN
  S --> VSN
  P --> VSN
  VSN -- email --> C
  VSN -- posts --> SN[Social networks<br/>Meta · TikTok · LinkedIn · X · YouTube · Pinterest · GBP]
  VSN --> EP[Email providers<br/>SMTP · Gmail · SendGrid · Mailchimp Tx · Brevo · Mailgun · SES · Resend · Postmark · Mailjet]
  VSN --> AI[AI providers<br/>OpenAI · Gemini · Claude]
  VSN --> AD[Ads APIs<br/>Meta · Google · TikTok · LinkedIn]
  VSN --> ST[Stripe<br/>Billing · Elements · Connect · Tax]
  VSN --> PU[Expo Push / APNs / FCM]
  EP -- webhooks --> VSN
  SN -- webhooks/metrics --> VSN
  ST -- webhooks --> VSN
```

## 2. Containers (C4 level 2)

```mermaid
flowchart TB
  subgraph Clients
    WEB[apps/web<br/>Next.js App Router · RSC · Tailwind v4 · shadcn/ui]
    MOB[apps/mobile<br/>Expo · React Native · Expo Router]
  end
  subgraph Shared packages
    APIC[packages/api-client<br/>generated from OpenAPI 3.1]
    TOK[packages/ui-tokens<br/>from Figma VSN Tokens]
    VAL[packages/validation<br/>Zod schemas]
  end
  subgraph Backend [Laravel backend · repo root]
    API[HTTP /api/v1<br/>Sanctum · RFC 9457 errors]
    MOD[Domain modules<br/>app/Modules/*]
    WK[Workers<br/>Horizon queues]
    RT[Reverb WebSockets]
    WH[Webhook ingress<br/>signature verify]
  end
  PG[(PostgreSQL 18)]
  RD[(Redis 8<br/>queues · cache · rate limits)]
  S3[(S3 storage<br/>assets · exports · invoices)]
  WEB --> APIC --> API
  MOB --> APIC
  WEB -. tokens .- TOK
  MOB -. tokens .- TOK
  API --> MOD --> PG
  MOD --> RD
  MOD --> S3
  WK --> MOD
  WH --> MOD
  MOD --> RT --> WEB
  RT --> MOB
```

Deployment:
- Next.js runs on Vercel or as a container.
- Laravel API, workers and Reverb run as containers (Docker), with managed PostgreSQL and Redis.
- Mobile ships through EAS.

Every environment has its own provider keys. Demo and preview environments only use sandbox adapters.

## 3. Module boundaries

Existing certified modules (`app/Modules/*`) are kept. New product modules follow the same layout:
- `Domain` (entities, rules)
- `Application` (use cases)
- `Infrastructure` (persistence, adapters)
- `Http` (controllers, resources)

A module touches another module's tables only through that module's application services or events, never directly.

| Module | Status | Owns | SRS |
|---|---|---|---|
| Core | existing | tenancy context, outbox, clock, idempotency | C-04 |
| Identity | existing + extend | users, workspaces, memberships, roles, permissions, sessions, SSO, invites | FR-IAM-* |
| Audit | existing | append-only audit events | FR-AU-01 |
| Consent | existing | consent records and lawful basis | C-03 |
| Contacts | existing | contacts, lists, tags, custom fields, imports | FR-EM-10 |
| Segmentation | existing | segment definitions and versions | FR-EM-12 |
| Providers | existing + extend | provider connections, credentials vault, limits, health, failover policy | FR-EM-01..03 |
| DeliveryEngine | existing | snapshots, admission, recovery, suppression, deliverability | FR-EM-04, FR-EM-15 |
| Templates / Content / Assets | existing + extend | templates, blocks, provider template imports, media | FR-EM-06/07 |
| Journeys | existing | automations runtime | FR-EM-13 |
| Experiments | existing | A/B tests | FR-EM-14 |
| Events | existing | customer and engagement events | FR-EM-08/09 |
| Analytics | existing + extend | facts, reports, schedules, exports | FR-RP-* |
| Publishing | existing + extend | campaigns, schedules, publication attempts | FR-EM-05 |
| AI | existing + extend | gateway, budgets, memories, provider adapters, brand kit | FR-AI-* |
| **Social** | new | social accounts, tokens, posts, variants, calendar, approvals, duplicate index, inbox, metrics | FR-SO-* |
| **Ads** | new | ad accounts, boosts, caps, wallet ledger, results | FR-AD-* |
| **Billing** | extend (billing tables exist) | plans, subscriptions, invoices, payment methods, dunning, entitlements | FR-BL-* |
| **Partners** | extend (agency client tables exist) | reseller clients, white-label, commissions, affiliates, referrals | FR-RS-* |
| **Marketplace** | new | vendors, listings, versions, orders, reviews, disputes, payouts | FR-MK-* |
| **Demo** | new | demo tenants, seeders, sandbox adapters, reset | FR-DEMO-* |
| **Notifications** | new | in-app/push/email notifications, preferences | FR-SHL-01, FR-MB-02 |
| **Search** | new | global search index (PostgreSQL FTS) and saved filters | FR-FL-* |

## 4. Data model (new and extended entities)

All tables carry `tenant_id` (except platform-level tables marked P), `created_at`, `updated_at` and soft-delete where noted. IDs are ULIDs. Money is stored as integer minor units plus an ISO 4217 currency.

```mermaid
erDiagram
  WORKSPACE ||--o{ MEMBERSHIP : has
  USER ||--o{ MEMBERSHIP : joins
  ROLE ||--o{ MEMBERSHIP : grants
  ROLE }o--o{ PERMISSION : includes
  WORKSPACE ||--o{ INVITE : sends

  WORKSPACE ||--o{ PROVIDER_CONNECTION : configures
  PROVIDER_CONNECTION ||--o{ PROVIDER_LIMIT_WINDOW : meters
  PROVIDER_CONNECTION ||--o{ PROVIDER_TEMPLATE : lists

  WORKSPACE ||--o{ SOCIAL_ACCOUNT : connects
  SOCIAL_ACCOUNT ||--|| SOCIAL_TOKEN : "encrypted"
  WORKSPACE ||--o{ SOCIAL_POST : drafts
  SOCIAL_POST ||--o{ SOCIAL_POST_VARIANT : "per network"
  SOCIAL_POST_VARIANT }o--|| SOCIAL_ACCOUNT : targets
  SOCIAL_POST_VARIANT ||--o{ SOCIAL_POST_METRIC : syncs
  SOCIAL_POST ||--o{ APPROVAL_REQUEST : needs
  WORKSPACE ||--o{ CONTENT_FINGERPRINT : "duplicate index"
  SOCIAL_ACCOUNT ||--o{ INBOX_THREAD : receives

  WORKSPACE ||--o{ AD_ACCOUNT : connects
  AD_ACCOUNT ||--o{ BOOST : runs
  BOOST }o--|| SOCIAL_POST_VARIANT : promotes
  WORKSPACE ||--|| SPEND_POLICY : caps
  WORKSPACE ||--o{ WALLET_ENTRY : ledger

  PLAN ||--o{ SUBSCRIPTION : "P: priced"
  WORKSPACE ||--o{ SUBSCRIPTION : buys
  SUBSCRIPTION ||--o{ INVOICE : bills
  INVOICE ||--o{ PAYMENT : settles
  WORKSPACE ||--o{ PAYMENT_METHOD : stores
  SUBSCRIPTION ||--o{ ENTITLEMENT : grants

  PARTNER ||--o{ CLIENT_LINK : "reseller clients"
  CLIENT_LINK }o--|| WORKSPACE : manages
  PARTNER ||--o{ REFERRAL : "affiliate"
  PARTNER ||--o{ COMMISSION_ENTRY : earns
  PARTNER ||--o{ PAYOUT : receives

  VENDOR ||--o{ LISTING : sells
  LISTING ||--o{ LISTING_VERSION : versions
  WORKSPACE ||--o{ ORDER : purchases
  ORDER }o--|| LISTING_VERSION : of
  ORDER ||--o{ REVIEW : rated
  ORDER ||--o{ DISPUTE : contests

  WORKSPACE ||--o{ BRAND_KIT : defines
  WORKSPACE ||--o{ AI_USAGE : meters
  WORKSPACE ||--o{ NOTIFICATION : notifies
  USER ||--o{ SAVED_FILTER : saves
```

Key fields and invariants:

| Entity | Key fields | Invariants |
|---|---|---|
| provider_connections | kind, label, credentials_ref (vault), priority, status, last_test_at | Credentials never leave the vault in plaintext; `priority` is unique per tenant |
| provider_limit_windows | window (sec/hour/day/month), limit, used, resets_at | `used` never exceeds `limit` (atomic Redis counter, reconciled to PG) |
| social_accounts | network, external_id, name, scopes[], health, token_expires_at | One tenant cannot attach an `external_id` already held by another tenant without proof of admin (the OAuth result) |
| social_posts / variants | body, media[], locale, status (draft → pending_approval → scheduled → publishing → published / failed), scheduled_at | Publish requires an approved request when policy demands one |
| content_fingerprints | simhash64, media_phash, network, posted_at | Used by the duplicate checker (Hamming distance ≤ 6 = near duplicate) |
| ad_accounts / boosts | budget_minor, currency, starts_at, ends_at, status | The sum of committed boost budgets must stay ≤ the remaining cap (checked in a transaction) |
| spend_policies | monthly_cap_minor, per_boost_max_minor, approver_role | Enforced server-side before any ads API call |
| wallet_entries | type (top_up, spend, refund), amount_minor, balance_after_minor, external_ref | Append-only double-entry; balance is never negative |
| plans (P) | code, prices[], limits{}, trial_days, active | Changes create a new version; existing subscriptions keep their version |
| subscriptions | plan_version, status (trialing, active, past_due, paused, canceled), period_end, stripe_id | One active subscription per workspace |
| entitlements | key, limit, used | Read by the limit guard (FR-BL-06) |
| partners (P) | type (reseller, affiliate), stripe_connect_id, status | Payouts only after Connect verification |
| vendors / listings | type, price_minor, status (draft, in_review, live, suspended) | Listing goes live only after moderation |
| orders | listing_version, amount_minor, fee_minor, refund_until, status | Payout is released after `refund_until` |
| notifications | channel (in_app, push, email), type, read_at | Respect user preferences and quiet hours |

Retention follows SRS NFR-PRV-02.

## 5. Data flows (sequence diagrams)

### 5.1 Email campaign send and tracking (FR-EM-04..09)

```mermaid
sequenceDiagram
  actor M as Marketer
  participant W as Web
  participant API as /api/v1
  participant PUB as Publishing
  participant DE as DeliveryEngine
  participant PR as Providers
  participant ESP as Email provider
  participant WH as Webhook ingress
  participant EV as Events/Analytics
  participant RT as Reverb
  M->>W: Schedule campaign
  W->>API: POST /campaigns/{id}/schedule
  API->>PUB: authorize + approval check
  PUB-->>API: pending_approval / scheduled
  Note over PUB: Approver approves (FR-SO-05 pattern)
  PUB->>DE: snapshot audience (consent + suppression filter)
  loop per batch (Horizon)
    DE->>PR: pick provider by priority and remaining limit
    PR->>ESP: send (idempotency key per recipient)
    alt provider error / limit reached
      PR->>PR: failover to next provider, log event
    end
  end
  ESP-->>WH: delivered / bounce / open / click / complaint
  WH->>EV: verify signature, normalize, classify real vs machine open
  EV->>RT: tenant channel update
  RT-->>W: live counters
```

### 5.2 Social connect and publish (FR-SO-01..07)

```mermaid
sequenceDiagram
  actor U as Admin
  participant W as Web/Mobile
  participant API as /api/v1
  participant SO as Social module
  participant NET as Network OAuth/API
  U->>W: Connect Facebook
  W->>API: POST /social/connect {network}
  API-->>W: authorize URL (state, PKCE)
  W->>NET: OAuth consent (minimum scopes)
  NET-->>API: GET /social/callback?code&state
  API->>SO: exchange code, store token in vault, list pages
  SO-->>W: candidate accounts
  U->>W: select pages + posting rules
  W->>API: POST /social/accounts (selection, rules)
  Note over SO: scheduler job at scheduled_at
  SO->>SO: duplicate check, approval check, health check
  SO->>NET: publish variant
  NET-->>SO: external post id
  SO->>SO: metrics sync job (scheduled + on demand)
```

### 5.3 AI generation (FR-AI-01..07)

```mermaid
sequenceDiagram
  participant W as Web
  participant API as /api/v1
  participant AI as AI gateway
  participant BUD as Budget/usage
  participant LLM as OpenAI/Gemini/Claude
  W->>API: POST /ai/generations {task, brief, locale}
  API->>BUD: check quota + cost cap
  BUD-->>API: ok / refused (402 limit)
  API->>AI: build prompt (brand kit, no PII unless enabled)
  AI->>LLM: request (platform key or BYOK)
  LLM-->>AI: output + token usage
  AI->>BUD: record tokens, cost
  AI-->>W: draft (never auto-sent)
```

### 5.4 Ad boost with caps (FR-AD-02/03)

```mermaid
sequenceDiagram
  actor M as Marketer
  actor A as Approver
  participant API as /api/v1
  participant ADS as Ads module
  participant NET as Ads API
  M->>API: POST /ads/boosts {post, budget, duration}
  API->>ADS: validate budget ≤ per-boost max and ≤ remaining monthly cap
  ADS-->>API: pending_approval
  A->>API: POST /approvals/{id}/approve
  API->>ADS: lock cap (SELECT … FOR UPDATE), reserve budget
  ADS->>NET: create campaign/ad set (budget = reserved)
  NET-->>ADS: ids
  loop results sync
    ADS->>NET: insights
    ADS->>ADS: if spend ≥ reserved → pause via API
  end
```

### 5.5 Subscription checkout (FR-BL-02..05)

```mermaid
sequenceDiagram
  actor O as Owner
  participant W as Web
  participant API as /api/v1
  participant BL as Billing
  participant ST as Stripe
  O->>W: Choose plan, billing details
  W->>API: POST /billing/checkout {plan, cycle, coupon}
  API->>BL: create/update customer, subscription (incomplete)
  BL->>ST: Subscription + PaymentIntent
  ST-->>W: client_secret → Stripe Elements confirm (3-D Secure)
  ST-->>API: webhook invoice.paid / payment_failed
  API->>BL: verify signature, idempotent apply
  BL->>BL: activate entitlements / start dunning
  BL-->>W: realtime status → success or failed screen
```

### 5.6 Marketplace purchase and payout (FR-MK-01..03)

```mermaid
sequenceDiagram
  actor B as Buyer
  participant API as /api/v1
  participant MK as Marketplace
  participant ST as Stripe Connect
  B->>API: POST /marketplace/orders {listing_version}
  API->>MK: price + tax + platform fee
  MK->>ST: PaymentIntent (application_fee, transfer_data → vendor)
  ST-->>API: webhook payment succeeded
  MK->>MK: install item into buyer workspace
  Note over MK: refund window 14 days
  MK->>ST: release transfer after refund window
```

## 6. Cross-cutting design

| Concern | Design |
|---|---|
| Tenancy | A `tenant_id` global scope is applied to every tenant model, and `TenantContext` is resolved per request or job. Cross-tenant test suite on every code PR (NFR-SEC-03) |
| Authorization | Permission catalog plus role policies; approval gates sit in application services, not controllers |
| Idempotency | `Idempotency-Key` header on POSTs that create money, send, publish or spend actions; outbox pattern for events |
| Secrets | Envelope-encrypted credential vault (per-tenant data key); key rotation command |
| Rate limits | Redis token buckets per tenant, per provider connection and per user |
| Realtime | Reverb private channels `tenant.{id}` and `user.{id}`; events are thin (IDs and counters) |
| Search | PostgreSQL `tsvector` columns and a `search_documents` table; Meilisearch only if NFR-PERF targets fail |
| i18n | Backend returns message keys and locale-aware formatting hints; translations live in clients |
| Observability | Request ID and tenant ID in every log line; Sentry; queue metrics in Horizon |
| Demo/sandbox | Adapter registry chooses `Sandbox*` adapters when the tenant is a demo tenant or live mode is off (C-02) |

## 7. Integration catalogue

| Integration | Adapter | Auth | Inbound | Limits handled |
|---|---|---|---|---|
| SMTP / Google SMTP | `SmtpTransport` | user/pass or OAuth2 XOAUTH2 | — (bounce mailbox optional) | per-connection rate config |
| SendGrid | `SendGridAdapter` | API key | Event Webhook (signed) | plan daily limit, 429 backoff |
| Mailchimp Transactional | `MandrillAdapter` | API key | webhooks (signed) | hourly quota |
| Brevo / Mailgun / SES / Resend / Postmark / Mailjet | one adapter each | API key / IAM | provider webhooks / SNS | provider quotas |
| Meta (Facebook, Instagram) | `MetaGraphAdapter` | OAuth (Facebook Login for Business) | webhooks (X-Hub-Signature-256) | app/page rate limits |
| TikTok, LinkedIn, X, YouTube, Pinterest, GBP | one adapter each | OAuth 2 + PKCE | webhooks where offered, else polling | per-network quotas |
| OpenAI / Gemini / Claude | `OpenAIAdapter`, `GeminiAdapter`, `AnthropicAdapter` | API key (platform/BYOK) | — | tokens per minute, cost caps |
| Meta Marketing / Google Ads / TikTok Ads / LinkedIn Ads | one adapter each | OAuth | — | spend caps (internal) |
| Stripe Billing, Elements, Connect, Tax | `StripeGateway` | secret key + webhook secret | webhooks (signed) | idempotency keys |
| Expo Push | `ExpoPushNotifier` | access token | receipts | batch of 100 |

Each adapter implements a provider-neutral interface, a `Sandbox` twin and a contract test. Research tasks (TASK-0109, 0117, 0123, 0127, 0131, 0140) confirm the current API versions before implementation.
