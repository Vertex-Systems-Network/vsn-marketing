# VSN Marketing — Software Requirements Specification (SRS)

| Field | Value |
|---|---|
| Document | SRS for the product track (PHASE-17..PHASE-24) |
| Standard | Structured after ISO/IEC/IEEE 29148:2018 (requirements specification content) |
| Version | 1.0 — 2026-10-17 |
| Owner task | TASK-0145 |
| Sources | [PRODUCT-TRACK-PLAN](../../.ai/roadmap/PRODUCT-TRACK-PLAN.md) §1 (R1–R24), [PRODUCT-SCREEN-MAP](../../.ai/roadmap/PRODUCT-SCREEN-MAP.md), [PRODUCT-STACK-INSTRUCTIONS](../../.ai/roadmap/PRODUCT-STACK-INSTRUCTIONS.md), [DESIGN-INDEX](DESIGN-INDEX.md), research packs in `.ai/research/PRODUCT-TRACK/` |
| Change rule | Later tasks that change behaviour update this file in the same PR; requirement IDs are never reused |

## 1. Introduction

### 1.1 Purpose
This document defines what the VSN Marketing product must do and how well it must do it. It is the single requirement baseline for the architecture work (TASK-0146), the UI/UX specs (TASK-0147), the engineering and QA guidelines (TASK-0148), and every implementation task from TASK-0103 to TASK-0144 and TASK-0149.

### 1.2 Scope
VSN Marketing is a multi-tenant SaaS for email marketing, social media management, AI content, ad boosting, analytics, billing, a seller marketplace and reseller/affiliate programmes. It ships as:
- a Next.js web app (public site plus panels);
- Android and iOS apps built with Expo;
- the existing certified Laravel backend, exposed through `/api/v1`.

The PHASE-00..16 backend modules (tenancy, RBAC, contacts, delivery engine, suppression, journeys, experiments, AI gateway, analytics, billing engine, privacy) are reused. This SRS covers the product layer built on top of them.

**Out of scope:**
- SMS and WhatsApp sending.
- Live payments or ad spend before owner accounts exist (sandbox only).
- A native desktop app.
- Self-hosted on-premise installs.

### 1.3 Definitions

| Term | Meaning |
|---|---|
| Workspace (tenant) | One customer organization; all customer data is keyed by `tenant_id` |
| Provider | External service used to send, post, generate or charge (SendGrid, Meta, OpenAI, Stripe…) |
| Real open / real click | Engagement after machine-open (e.g. Apple MPP) and bot-click filtering |
| Approval | Human confirmation required before a send, publish or spend when policy requires it |
| Hard cap | Spend limit the system enforces and never exceeds, whatever the provider does |
| Sandbox / demo mode | A provider adapter that records actions but never sends, posts, charges or spends |
| BYOK | Bring-your-own API key for an AI or email provider |
| RTL | Right-to-left layout (Urdu) |

### 1.4 Requirement notation
- **Functional requirements** have IDs `FR-<area>-<nn>`. They trace to an owner requirement R1–R24 and to the implementing task(s).
- **Non-functional requirements** have IDs `NFR-<area>-<nn>`. Each has a measurable target and a verification method.
- **Priority:** **M** = must, needed for that phase's certification; **S** = should; **C** = could.
- **Verification method:**
  - T = automated test (unit / feature / E2E)
  - A = analysis or audit
  - I = inspection or review evidence
  - D = demo in the demo system

## 2. Overall description

### 2.1 Product perspective
There is one API backend and three clients. Clients never talk to providers directly: every send, post, AI call, charge and ad spend goes through backend adapters. Those adapters enforce tenancy, RBAC, approvals, quotas, caps and audit.

```
Web (Next.js)  ─┐
Android (Expo) ─┼─> /api/v1 (Laravel, Sanctum) ─> domain modules ─> provider adapters ─> SendGrid/SES/SMTP…, Meta/TikTok/LinkedIn…, OpenAI/Gemini/Claude, Stripe, Ads APIs
iOS (Expo)     ─┘                               └─> PostgreSQL 18, Redis 8, S3, Reverb (realtime)
```

### 2.2 User classes and roles
The 12 roles from the screen map are listed below. Custom roles are built from the permission catalog and can never exceed the creator's own permissions.

| # | Role | Scope | Typical user |
|---|---|---|---|
| 1 | Super Admin | Whole platform | VSN owner/operator |
| 2 | Support Agent | Platform, read-mostly; impersonation with reason and audit | VSN support |
| 3 | Owner | One workspace, including billing and deletion | Business founder |
| 4 | Admin | Workspace settings, team, all channels; no workspace deletion | Marketing lead |
| 5 | Marketer | Create campaigns, posts and AI drafts; send/publish/spend only if approval policy allows | Marketer |
| 6 | Approver/Reviewer | Approve or reject campaigns, posts and spend | Manager |
| 7 | Analyst | Reports and exports only | Analyst |
| 8 | Viewer | Read only | Stakeholder |
| 9 | Seller — Reseller/Agency | Own client sub-accounts, white-label, client billing, commissions | Agency |
| 10 | Seller — Marketplace vendor | Own listings, orders, reviews, payouts | Template/service seller |
| 11 | Affiliate | Referral links, clicks, sign-ups, earnings | Partner |
| 12 | Agency client user | Limited access inside one delegated client workspace | Agency's client |

### 2.3 Operating environment
- **Web:** current and previous major versions of Chrome, Edge, Firefox and Safari; responsive from 360 px to 1920 px.
- **Mobile:** Android 10+ and iOS 16+.
- **Backend:** PHP 8.3+, Laravel, PostgreSQL 18, Redis 8, S3-compatible storage. Versions are as listed in PRODUCT-STACK-INSTRUCTIONS.

### 2.4 Constraints
1. **C-01** — AI-native delivery (ADR-0006/0007): all engineering is done by AI agents. Owner-only inputs are external accounts and keys, app-store accounts, domain/hosting and live money.
2. **C-02** — Nothing goes live without the owner's decision:
   - real email sending, social publishing and ad spend stay disabled until the owner supplies accounts and enables them;
   - Stripe runs in test mode until then.
3. **C-03** — No spam and no consent bypass:
   - sending requires a lawful basis or consent record, a visible unsubscribe and suppression checks;
   - purchased lists are rejected at import.
4. **C-04** — Tenant isolation: no query, cache key, file path, queue job or webhook can read or write another tenant's data.
5. **C-05** — No secrets in the repository. Provider credentials are stored encrypted per tenant and never returned in full after save.
6. **C-06** — Minimal GitHub-runner usage per PRODUCT-STACK-INSTRUCTIONS §0.4:
   - docs-only PRs run lightweight CI;
   - heavy jobs run only at certification.
7. **C-07** — Design source: tokens trace to Figma file `JqukmwKhVyObh1SQ6gsKR0`; screens live in file `5lMnkndlKLHunSFGBJYhDN`.

### 2.5 Assumptions and dependencies
- Social publishing depends on platform app review (Meta, TikTok, LinkedIn, X). Until approval, the adapters run in sandbox.
- Real-open detection uses heuristics (Apple MPP proxy IPs, prefetch timing, scanner user-agents). It is reported as an estimate with its method shown.

## 3. Functional requirements

### 3.1 Platform, API and shell (R17, R20, R22, R23, R24)

| ID | Requirement | Pri | R | Task(s) | Verify |
|---|---|---|---|---|---|
| FR-API-01 | Expose all product functions through versioned REST `/api/v1`, described by an OpenAPI 3.1 document that is generated and checked in CI | M | R20 | TASK-0103 | T |
| FR-API-02 | Authenticate the web with Sanctum cookie sessions and mobile with personal access tokens; every request resolves exactly one active tenant | M | R17 | TASK-0103 | T |
| FR-API-03 | Generate a typed TypeScript client from the OpenAPI document and use it in web and mobile; no hand-written fetch calls to product endpoints | M | R20 | TASK-0103, TASK-0104 | I |
| FR-API-04 | Return errors as RFC 9457 problem details with a stable `code`, a field-level `errors` map and a request ID | M | R20 | TASK-0103 | T |
| FR-API-05 | Use cursor pagination, sorting and filter parameters consistently on every list endpoint | M | R19 | TASK-0103, TASK-0137 | T |
| FR-API-06 | Emit signed outbound webhooks (HMAC-SHA256, timestamp, retries with backoff) for campaign, post, payment and contact events | S | R17 | TASK-0103 | T |
| FR-SHL-01 | The web shell provides a collapsible sidebar with tooltips when collapsed, a top bar with global search (⌘K), notifications, a workspace switcher and a user menu | M | R20 | TASK-0104 | T, D |
| FR-SHL-02 | The shell renders the navigation for the signed-in role's panel (Buyer, Super Admin, Seller) and hides items the role lacks permission for | M | R17 | TASK-0104, TASK-0106 | T |
| FR-SHL-03 | Every list, detail and form screen shows designed loading (skeleton), empty, error, permission-denied and offline states | M | R20 | TASK-0104 + feature tasks | T |
| FR-SHL-04 | The public website delivers every page listed in screen map §1, including legal pages and a consent-first cookie banner (no non-essential cookies before consent) | M | R20 | TASK-0104, TASK-0138 | T, I |
| FR-SHL-05 | Onboarding wizard: company → email provider → contacts → social → first campaign or post; every step can be skipped and resumed | M | R20 | TASK-0104, TASK-0110, TASK-0118 | D |
| FR-I18N-01 | Every UI string is externalized (next-intl on web, i18n on mobile). English and Urdu ship at launch; adding a language needs no code change | M | R22 | TASK-0104, TASK-0141 | T |
| FR-I18N-02 | Urdu switches the layout to RTL (`dir="rtl"`), mirrors directional icons and uses Noto Nastaliq Urdu for headings and Noto Sans Arabic for UI text; numbers, dates and currency follow the user's locale | M | R22 | TASK-0104, TASK-0147 | T, D |
| FR-I18N-03 | Users choose their language in their profile; the workspace sets a default; emails and posts carry their own content language, independent of the UI language | M | R22 | TASK-0105 | T |
| FR-I18N-04 | The AI writer can generate, translate and rewrite content in Urdu (script and Roman Urdu) and English | S | R22, R15 | TASK-0125 | D |
| FR-DEMO-01 | Demo mode uses sandbox adapters for every provider: no real send, post, charge or spend is possible in a demo tenant | M | R23 | TASK-0149 | T |
| FR-DEMO-02 | Demo seeders create dummy data for every module and screen, plus one demo user per role (12) | M | R23 | TASK-0149 | D |
| FR-DEMO-03 | One-click demo reset, and a public "Try demo" login on the landing page that opens a fresh, isolated, time-limited demo tenant | M | R23 | TASK-0149 | T |

### 3.2 Identity, roles and panels (R17)

| ID | Requirement | Pri | R | Task(s) | Verify |
|---|---|---|---|---|---|
| FR-IAM-01 | Sign up with email verification. Log in, forgot/reset password, TOTP 2FA with recovery codes, SSO (OIDC/SAML on Agency plan) and invite accept | M | R17 | TASK-0105 | T |
| FR-IAM-02 | Ship the 12 default roles with the permission matrix in §3.11; Owners and Admins can build custom roles from the permission catalog | M | R17 | TASK-0105 | T |
| FR-IAM-03 | Team invites by email with role, expiry (7 days), resend and revoke; seat limits follow the plan | M | R17, R16 | TASK-0105 | T |
| FR-IAM-04 | Session management: list and revoke sessions and devices; optional workspace-wide 2FA enforcement | M | R17 | TASK-0105 | T |
| FR-PNL-01 | Buyer panel provides every screen in screen map §3 | M | R17 | TASK-0106 + feature tasks | D |
| FR-PNL-02 | Super Admin panel provides every screen in screen map §4. Impersonation requires a reason, is time-boxed (≤ 60 min), shows a banner to the agent and is written to the audit log | M | R17 | TASK-0106 | T |
| FR-PNL-03 | Seller panel provides the reseller, marketplace vendor and affiliate areas in screen map §5 | M | R17 | TASK-0106, TASK-0133, TASK-0134 | D |
| FR-PNL-04 | Resellers create client sub-accounts with branding and limits, and get delegated access to them; agency client users see only their own workspace | M | R17 | TASK-0106, TASK-0133 | T |

### 3.3 Email marketing (R1–R10)

| ID | Requirement | Pri | R | Task(s) | Verify |
|---|---|---|---|---|---|
| FR-EM-01 | Connect providers: SMTP (any), Google SMTP/Gmail API, SendGrid, Mailchimp Transactional, Brevo, Mailgun, Amazon SES, Resend, Postmark, Mailjet. Every connection has a connection test | M | R2 | TASK-0109, TASK-0110 | T |
| FR-EM-02 | Show each provider's sending limits (per second, hour, day, month), current usage and remaining quota as a meter; warn at 80% and stop at 100% | M | R4 | TASK-0110 | T |
| FR-EM-03 | Configurable provider priority with automatic failover when a provider errors, rate-limits or is exhausted; failover events are logged and notified | M | R2 | TASK-0110 | T |
| FR-EM-04 | Send emails (campaign and transactional) through the selected or failover provider, with throttling, retries and an idempotency key per recipient | M | R1 | TASK-0113 | T |
| FR-EM-05 | Campaign wizard: audience → content → schedule (now, later, time-zone send) → review/approval → send; supports test sends and drafts | M | R10, R1 | TASK-0113 | T, D |
| FR-EM-06 | List each provider's own templates and import them into the template library | M | R3 | TASK-0112 | T |
| FR-EM-07 | Template studio: drag-and-drop blocks, an HTML/code mode, brand kit, dynamic fields, desktop/mobile preview, dark-mode preview and test send; own templates are saved per workspace | M | R6 | TASK-0112 | T, D |
| FR-EM-08 | Track per-recipient status: queued, sent, delivered, bounced (hard/soft), opened, clicked, unsubscribed, complained. Sources are provider webhooks, a tracking pixel and signed redirect links | M | R5, R9 | TASK-0114 | T |
| FR-EM-09 | Separate real opens from machine opens (e.g. Apple MPP, security scanners) and real clicks from bot clicks; show both totals and the method used | M | R5, R9 | TASK-0114 | T |
| FR-EM-10 | Contacts: CSV/XLSX import with field mapping, consent source and dedupe; lists, tags, custom fields; contact profile with timeline | M | R7 | TASK-0111 | T |
| FR-EM-11 | Health analysis: classify addresses as active, inactive (configurable days, default 180), bounced, complained, unsubscribed, role-based or invalid syntax/MX. Bulk actions: suppress or re-engage | M | R8 | TASK-0111 | T |
| FR-EM-12 | Segments: rule builder (fields, tags, engagement, purchases) with a live count and preview; segments update dynamically | M | R10 | TASK-0115 | T |
| FR-EM-13 | Automations: visual builder (trigger, wait, condition, email, tag, webhook) with ready recipes (welcome, abandoned cart, win-back, birthday) | M | R10 | TASK-0115 | T, D |
| FR-EM-14 | A/B tests on subject, content and send time, with a test share, winner metric (real opens, real clicks, revenue) and automatic winner send | M | R10 | TASK-0115 | T |
| FR-EM-15 | Deliverability: sending-domain setup with SPF/DKIM/DMARC DNS check, reputation and bounce/complaint trend, and suppression list management | M | R10, R8 | TASK-0115 | T |
| FR-EM-16 | Every marketing email includes a one-click unsubscribe (RFC 8058 List-Unsubscribe-Post) and the sender's postal address; suppressed addresses are never sent to | M | R1 | TASK-0113 | T |

### 3.4 Social media (R11, R12, R14)

| ID | Requirement | Pri | R | Task(s) | Verify |
|---|---|---|---|---|---|
| FR-SO-01 | Connect-social wizard covering Facebook Page, Instagram Business, TikTok Business, LinkedIn Page, X, YouTube, Pinterest and Google Business Profile: choose network → OAuth sign-in & permissions → pick pages/accounts → posting rules → connected. Requests only the minimum scopes and explains each one | M | R11 | TASK-0117, TASK-0118 | T, D |
| FR-SO-02 | Token refresh and account health view (healthy, expiring, revoked, missing permission). Posts scheduled to an unhealthy account pause and notify | M | R11 | TASK-0118 | T |
| FR-SO-03 | Composer: one draft for many networks with per-network previews and limits (length, media type/size, hashtags), media library, first comment, link shortener | M | R12 | TASK-0119 | T, D |
| FR-SO-04 | Shared calendar (week and month) for social and email, drag to reschedule, queue with best-time slots, published list, drafts | M | R12 | TASK-0119 | T |
| FR-SO-05 | Approval workflow for posts, configurable per account and role, with comments and version history | M | R12 | TASK-0119 | T |
| FR-SO-06 | AI post assistant: generate, rewrite, hashtag, translate and image-prompt in the brand voice | M | R12, R15 | TASK-0120 | D |
| FR-SO-07 | Duplicate checker: detect near-duplicate text and media across the workspace's networks and history (default window 30 days, similarity threshold configurable); warn before scheduling | M | R12 | TASK-0120 | T |
| FR-SO-08 | Social account data controls: download all data VSN holds for a connected account, update the stored profile, and delete it (removing tokens and cached content) | M | R14 | TASK-0121 | T |
| FR-SO-09 | Unified inbox for comments and messages, with assignment and AI-suggested replies; sending a reply always needs a human action | S | R11 | TASK-0121 | D |
| FR-SO-10 | Post performance sync (reach, impressions, engagement, clicks, video views) on a schedule and on demand | M | R12, R18 | TASK-0121 | T |

### 3.5 AI (R15)

| ID | Requirement | Pri | R | Task(s) | Verify |
|---|---|---|---|---|---|
| FR-AI-01 | Live adapters for OpenAI, Google Gemini and Anthropic Claude behind the AI gateway; models are selectable per feature | M | R15 | TASK-0123, TASK-0124 | T |
| FR-AI-02 | Platform keys or BYOK per workspace. BYOK keys are encrypted, testable and never shown again | M | R15 | TASK-0124 | T |
| FR-AI-03 | Usage metering per workspace and user (tokens, cost), plan quotas, hard monthly cost caps and alerts at 80% | M | R15, R16 | TASK-0124 | T |
| FR-AI-04 | AI writer: email copy, subject lines, social posts, rewrite, shorten, translate (including Urdu), and tone and brand-voice control | M | R15 | TASK-0125 | D |
| FR-AI-05 | Campaign planner: generate a multi-channel plan (email + social) for a goal and date range as drafts; nothing is sent or scheduled without human confirmation | S | R15 | TASK-0125 | D |
| FR-AI-06 | Brand kit: voice, banned words, logo, colours and sample content, used as context in every AI call | M | R15 | TASK-0125 | T |
| FR-AI-07 | Do not send customer contact PII to AI providers unless the workspace enables it. Prompts and outputs are logged for 30 days for audit, then purged | M | R15 | TASK-0124 | T, A |

### 3.6 Ads and boosting (R13)

| ID | Requirement | Pri | R | Task(s) | Verify |
|---|---|---|---|---|---|
| FR-AD-01 | Connect ad accounts (Meta Marketing API, Google Ads, TikTok Ads, LinkedIn Ads) and select the ad account | M | R13 | TASK-0127, TASK-0128 | T |
| FR-AD-02 | Workspace and per-boost hard caps. Each boost needs an approver if the policy says so; the system refuses any spend above the cap | M | R13 | TASK-0128 | T |
| FR-AD-03 | Boost-a-post: audience, budget, duration, preview and estimated reach; submit after approval | M | R13 | TASK-0128 | T, D |
| FR-AD-04 | Ad wallet (prepaid balance) with top-up, optional auto top-up, history and refunds of unused balance | S | R13, R16 | TASK-0128, TASK-0132 | T |
| FR-AD-05 | Ad results: spend, reach, clicks, CPC, conversions and ROAS, combined with organic and email results in one view | M | R13, R18 | TASK-0129 | T |

### 3.7 Packages, billing, reseller, affiliate and marketplace (R16, R17)

| ID | Requirement | Pri | R | Task(s) | Verify |
|---|---|---|---|---|---|
| FR-BL-01 | Plans Free, Starter, Pro and Agency, with limits for emails/month, contacts, social accounts, seats, AI credits and ads, monthly/yearly prices and trials | M | R16 | TASK-0131, TASK-0132 | T |
| FR-BL-02 | Self-serve checkout:<br>1. Choose plan<br>2. Billing details incl. VAT ID<br>3. Payment method: card, SEPA, PayPal; bank transfer on Agency<br>4. 3-D Secure (SCA)<br><br>Then processing, success and failure screens. Card data is collected only by Stripe Elements | M | R16 | TASK-0132 | T, D |
| FR-BL-03 | Upgrade or downgrade with proration; cancellation flow (reason → retention offer: pause or downgrade → confirm); access continues to the end of the paid period | M | R16 | TASK-0132 | T |
| FR-BL-04 | Invoices and receipts as PDF with tax lines; payment history; payment methods (add, set default, remove) | M | R16 | TASK-0132 | T |
| FR-BL-05 | Trial-ending reminders (7, 3 and 1 day). Dunning with smart retries; sending pauses after a grace period (default 7 days) without deleting data | M | R16 | TASK-0132 | T |
| FR-BL-06 | When a limit is reached, show an upgrade dialog. The limit is enforced server-side; the UI never lets an over-limit action through | M | R16 | TASK-0132 | T |
| FR-RS-01 | Reseller/agency: client sub-accounts, white-label (logo, colours, custom domain), client billing at the reseller's price, and a commission ledger | M | R17 | TASK-0133 | T |
| FR-RS-02 | Affiliate programme: referral links, click and sign-up attribution (default 30-day cookie, with consent), recurring commission rules, earnings and payouts | M | R17 | TASK-0133 | T |
| FR-MK-01 | Marketplace vendor onboarding with Stripe Connect (identity, bank, tax form) before the first payout | M | R17 | TASK-0134 | T |
| FR-MK-02 | Listings (templates, automation recipes, AI prompt packs, integrations, services) with versions, pricing, review/moderation before going live, and buyer reviews | M | R17 | TASK-0134 | T |
| FR-MK-03 | Marketplace checkout and purchase flow; purchases install into the buyer workspace; a refund window (default 14 days); disputes; platform fee | M | R17, R16 | TASK-0134 | T, D |

### 3.8 Reports and filters (R18, R19)

| ID | Requirement | Pri | R | Task(s) | Verify |
|---|---|---|---|---|---|
| FR-RP-01 | Analytics overview and per-channel views (email, social, ads) with funnels, cohorts and attribution | M | R18 | TASK-0136 | T |
| FR-RP-02 | Report builder with metrics picker, date range, comparison, branding (white-label for resellers), scheduled email delivery and CSV/PDF export | M | R18 | TASK-0136 | T, D |
| FR-FL-01 | Global search (⌘K) across campaigns, posts, contacts, templates, listings and settings, respecting permissions | M | R19 | TASK-0137 | T |
| FR-FL-02 | Every list screen has filters, sort, column choice and saved filters (personal or shared) | M | R19 | TASK-0137 | T |

### 3.9 Mobile apps (R21)

| ID | Requirement | Pri | R | Task(s) | Verify |
|---|---|---|---|---|---|
| FR-MB-01 | Android and iOS apps from one Expo codebase: onboarding, login/SSO, 2FA, workspace switcher | M | R21 | TASK-0140, TASK-0141 | T |
| FR-MB-02 | Home KPIs and alerts; approvals for campaigns, posts and ad spend, with push notifications | M | R21 | TASK-0142 | T, D |
| FR-MB-03 | Composer (camera/gallery), calendar, queue, inbox; email campaign list, live stats and quick send of approved campaigns; AI writer | M | R21 | TASK-0142 | D |
| FR-MB-04 | Mobile connect-social, checkout/payment-result and ad-wallet top-up flows, matching the web rules (FR-SO-01, FR-BL-02, FR-AD-04). Purchases use the store's in-app purchase where store rules require it | S | R21, R16 | TASK-0142 | D |
| FR-MB-05 | Store release pipeline (EAS Build/Submit, TestFlight, Play internal track) | M | R21 | TASK-0143 | I |

### 3.10 Privacy, audit and administration (cross-cutting)

| ID | Requirement | Pri | R | Task(s) | Verify |
|---|---|---|---|---|---|
| FR-PR-01 | Workspace data export (JSON/CSV) and deletion with a 30-day recovery window, plus legal holds | M | R14, R17 | TASK-0106 (reuse TASK-0096) | T |
| FR-PR-02 | Contact-level access, export, rectification and erasure requests (GDPR Art. 15–17), completed within 30 days | M | R7 | TASK-0111 | T |
| FR-AU-01 | Append-only audit log of security-relevant and money-relevant actions (login, role change, impersonation, send, publish, spend, refund, key change), searchable by Admin and Super Admin | M | R17 | TASK-0106 | T |
| FR-SA-01 | Super Admin: provider health, AI usage and costs, feature flags, system emails, logs, backups/restore status, security events, support tickets and content CMS | M | R17 | TASK-0106, TASK-0136 | D |

### 3.11 Permission matrix (12 roles)

Legend:
- **F** = full
- **C** = create/edit
- **A** = approve
- **R** = read
- **O** = own records only
- **—** = no access
- **(ap)** = action allowed only through the approval workflow when the workspace requires approval

Workspace roles (Owner → Viewer, plus Agency client user) apply inside one workspace. Platform and seller roles act in their own area.

| Capability | Super Admin | Support | Owner | Admin | Marketer | Approver | Analyst | Viewer | Reseller | Vendor | Affiliate | Agency client |
|---|---|---|---|---|---|---|---|---|---|---|---|---|
| Platform dashboard, tenants, users | F | R | — | — | — | — | — | — | — | — | — | — |
| Impersonate a tenant (audited) | F | C | — | — | — | — | — | — | O (own clients) | — | — | — |
| Plans, coupons, refunds (platform) | F | R | — | — | — | — | — | — | — | — | — | — |
| Workspace settings, brands | R | R | F | F | R | R | R | R | F (clients) | — | — | R |
| Team, roles, invites | R | R | F | F | — | — | — | — | F (clients) | — | — | — |
| Billing, plan, payment methods | R | R | F | R | — | — | — | — | F (client billing) | — | — | — |
| Email providers, API keys, webhooks | R | R | F | F | R | — | — | — | F (clients) | — | — | — |
| Contacts, lists, imports | R | R | F | F | C | R | R | R | F (clients) | — | — | C |
| Templates | R | R | F | F | C | R | R | R | F (clients) | — | — | C |
| Campaigns: create | R | R | F | F | C | R | — | — | F (clients) | — | — | C |
| Campaigns: send | — | — | F | F | C (ap) | A | — | — | F (clients) | — | — | C (ap) |
| Social accounts: connect / data delete | — | — | F | F | — | — | — | — | F (clients) | — | — | — |
| Posts: create / schedule | R | R | F | F | C | R | — | — | F (clients) | — | — | C |
| Posts: publish | — | — | F | F | C (ap) | A | — | — | F (clients) | — | — | C (ap) |
| Ad accounts and caps | R | R | F | F | — | R | R | — | F (clients) | — | — | — |
| Ad spend / boost | — | — | F | F (ap) | C (ap) | A | — | — | F (ap) | — | — | — |
| AI writer / planner | — | — | F | F | C | C | — | — | F | C | — | C |
| AI keys and cost caps | R | R | F | F | — | — | — | — | F (clients) | — | — | — |
| Analytics, reports, exports | F | R | F | F | R | R | F | R | F (clients) | O | O | R |
| Marketplace: buy | — | — | F | F | — | — | — | — | F | — | — | — |
| Marketplace: listings, orders, payouts | F (moderate) | R | — | — | — | — | — | — | — | O | — | — |
| Affiliate links and earnings | F | R | — | — | — | — | — | — | — | — | O | — |
| Commissions and payouts | F | R | — | — | — | — | — | — | O | O | O | — |
| Privacy export / workspace deletion | F | R | F | R (export) | — | — | — | — | F (clients) | — | — | — |
| Audit log | F | R | R | R | — | — | — | — | R (clients) | — | — | — |

Rules:
- **IAM-R1:** Approval roles are enforced by the server. A user can never approve their own item unless the workspace has a single member.
- **IAM-R2:** Platform roles (Super Admin, Support) act on tenant data only by impersonating, and only with a recorded reason.
- **IAM-R3:** Custom roles are subsets of the catalog. The UI and the API both reject privilege escalation.

## 4. External interface requirements

### 4.1 User interfaces
- **UI-01:** Screens follow the Figma product file `5lMnkndlKLHunSFGBJYhDN` (indexed in DESIGN-INDEX). Screen specs are in TASK-0147.
- **UI-02:** Tokens come only from `packages/ui-tokens`, which traces to Figma `JqukmwKhVyObh1SQ6gsKR0`. Code must not contain hard-coded hex colours.
- **UI-03:** Layout:
  - Sidebar 240 px expanded / 72 px collapsed.
  - Content max width 1440 px.
  - Mobile frames are 390 × 844 with a bottom tab bar.

### 4.2 Software interfaces (integration catalogue summary)

| Area | Interfaces | Mode until owner keys exist |
|---|---|---|
| Email | SMTP, Gmail API, SendGrid, Mailchimp Transactional, Brevo, Mailgun, SES, Resend, Postmark, Mailjet (send + webhooks + templates) | Sandbox adapter |
| Social | Meta Graph (Facebook, Instagram), TikTok, LinkedIn, X, YouTube Data, Pinterest, Google Business Profile | Sandbox adapter |
| AI | OpenAI, Google Gemini, Anthropic Claude | Offline/mock adapter |
| Ads | Meta Marketing, Google Ads, TikTok Ads, LinkedIn Ads | Sandbox, no spend |
| Payments | Stripe Billing, Stripe Elements, Stripe Connect, Stripe Tax; App Store / Play billing where required | Stripe test mode |
| Infra | PostgreSQL 18, Redis 8, S3-compatible storage, Reverb WebSockets, Expo Push, Sentry | Local/dev |

TASK-0146 records the full contracts (endpoints, scopes, webhooks, rate limits).

### 4.3 Communications interfaces
- **COM-01:** All traffic uses HTTPS with TLS 1.2+; HSTS is on for the web.
- **COM-02:** Every inbound webhook has its signature verified (Stripe, SendGrid, Meta…). Replays older than 5 minutes are rejected.
- **COM-03:** Realtime updates (tracking counters, post status, notifications) use authenticated, per-tenant private channels.

## 5. Non-functional requirements (measurable)

| ID | Area | Requirement and target | Verify |
|---|---|---|---|
| NFR-PERF-01 | Web performance | Core Web Vitals at p75 on the public site: LCP ≤ 2.5 s, INP ≤ 200 ms, CLS ≤ 0.1 | Lighthouse CI + RUM (T) |
| NFR-PERF-02 | App performance | Dashboard first contentful render ≤ 1.5 s and route transitions ≤ 300 ms on a mid-range laptop over 4G profile | Playwright trace (T) |
| NFR-PERF-03 | API latency | Read endpoints p95 ≤ 300 ms and write endpoints p95 ≤ 600 ms at 200 requests/s per node | k6 load test at certification (T) |
| NFR-PERF-04 | Sending throughput | ≥ 100,000 emails/hour per worker pool, subject to provider limits; queue lag p95 ≤ 60 s | Benchmark (T) |
| NFR-PERF-05 | Tracking freshness | Open, click and delivery events visible in the UI within 10 s (p95) of webhook receipt | E2E with Reverb (T) |
| NFR-PERF-06 | Mobile | Cold start ≤ 2.5 s on a mid-range Android device; JS bundle ≤ 8 MB | Device test (T) |
| NFR-AVL-01 | Availability | 99.9% monthly for API and web, excluding announced maintenance (≤ 4 h/month) | Uptime monitoring (A) |
| NFR-AVL-02 | Recovery | RPO ≤ 15 min (WAL archiving); RTO ≤ 4 h; restore drill every quarter | Drill evidence (I) |
| NFR-SEC-01 | AppSec | OWASP ASVS 4.0 Level 2 controls for auth, session, access control, validation and crypto; zero open critical/high SAST or dependency findings at release | CI scans + checklist (A) |
| NFR-SEC-02 | Secrets | Provider credentials encrypted at rest (AES-256 envelope, per-tenant key); never logged; masked after save | Test + log scan (T) |
| NFR-SEC-03 | Tenant isolation | 100% of tenant-scoped models carry tenant scoping, verified by an automated cross-tenant access test suite on every code PR | T |
| NFR-SEC-04 | Auth hardening | Login rate limit 5 attempts/min per account+IP; password policy per NIST SP 800-63B (≥ 12 chars, breached-password check); 2FA available to all users and enforceable per workspace | T |
| NFR-SEC-05 | Money safety | 0 spend or charge above a configured cap. Every money action is idempotent and audited | Adversarial tests (T) |
| NFR-PRV-01 | Privacy | GDPR/UK GDPR and PDPA-aligned: records of consent, DPA available, data export/erasure within 30 days, EU data residency option | A, T |
| NFR-PRV-02 | Retention | Raw tracking events 13 months; AI prompt logs 30 days; deleted workspaces purged after 30 days; backups rotate within 35 days | A |
| NFR-PRV-03 | Anti-spam | Complaint rate kept < 0.3% (alert at 0.1%) and hard-bounce rate < 2% per campaign. A campaign exceeding the limits auto-pauses | T |
| NFR-ACC-01 | Accessibility | WCAG 2.2 AA on web and mobile: 0 serious/critical axe violations per screen, keyboard-complete flows, visible focus (3 px ring), text contrast ≥ 4.5:1, touch targets ≥ 44 × 44 px | axe + Playwright (T), manual audit (I) |
| NFR-I18N-01 | Localization | 100% of UI strings available in English and Urdu at release; RTL visual tests for every panel template; no truncation at +40% string length | T |
| NFR-SCL-01 | Scalability | Supports 10,000 workspaces, 50 M contacts and 1 M social posts/month on horizontal scaling without schema change | Load test + analysis (A) |
| NFR-OBS-01 | Observability | Structured logs with request and tenant IDs; error tracking (Sentry) on web, mobile and API; alert on error rate > 1% for 5 min | I |
| NFR-MNT-01 | Maintainability | Backend and TypeScript packages ≥ 80% line coverage on changed code; strict TypeScript; lint clean; OpenAPI schema diff check in CI | CI (T) |
| NFR-CMP-01 | Compatibility | Web supports the last 2 major browser versions; mobile supports Android 10+ and iOS 16+ | Test matrix (T) |
| NFR-CI-01 | CI cost | Docs/plan PRs run lightweight control CI only; heavy suites run only on code PRs and certifications | I |

## 6. Acceptance criteria per release

| Release | Certification task | Acceptance criteria |
|---|---|---|
| PHASE-17 shell | TASK-0108 | All of: FR-API-*, FR-SHL-*, FR-I18N-01..03, FR-IAM-*, FR-PNL-*, FR-DEMO-* pass; NFR-ACC-01, NFR-SEC-03 and NFR-I18N-01 met for shipped screens |
| PHASE-18 email | TASK-0116 | All of: FR-EM-01..16 pass in sandbox and with at least one live provider when owner keys exist; NFR-PERF-04, NFR-PERF-05 and NFR-PRV-03 met |
| PHASE-19 social | TASK-0122 | FR-SO-01..10 pass; the connect wizard works end-to-end in sandbox and on approved networks |
| PHASE-20 AI | TASK-0126 | FR-AI-01..07 pass; cost caps proven by adversarial test |
| PHASE-21 ads | TASK-0130 | FR-AD-01..05 pass; NFR-SEC-05 proven (no spend above cap) |
| PHASE-22 billing | TASK-0135 | FR-BL-*, FR-RS-* and FR-MK-* pass in Stripe test mode |
| PHASE-23 launch | TASK-0139 | FR-RP-* and FR-FL-* pass; all NFR targets measured and met; owner go-live decision recorded |
| PHASE-24 mobile | TASK-0144 | FR-MB-01..05 pass; store builds on internal tracks |

## 7. Traceability

### 7.1 Owner requirement → SRS → task

| R | Owner requirement | SRS IDs | Tasks |
|---|---|---|---|
| R1 | Send emails | FR-EM-04, FR-EM-05, FR-EM-16 | TASK-0110, TASK-0113 |
| R2 | Email providers | FR-EM-01, FR-EM-03 | TASK-0109, TASK-0110 |
| R3 | Provider templates | FR-EM-06 | TASK-0112 |
| R4 | Provider sending limits | FR-EM-02 | TASK-0110 |
| R5 | Sent/delivered/opened/clicked | FR-EM-08, FR-EM-09 | TASK-0114 |
| R6 | Own templates | FR-EM-07 | TASK-0112 |
| R7 | Address lists | FR-EM-10, FR-PR-02 | TASK-0111 |
| R8 | Active/disabled analysis | FR-EM-11, FR-EM-15 | TASK-0111, TASK-0115 |
| R9 | Email tracking | FR-EM-08, FR-EM-09 | TASK-0114 |
| R10 | Email sub-options | FR-EM-05, FR-EM-12..15 | TASK-0113, TASK-0115 |
| R11 | Social posting management | FR-SO-01, FR-SO-02, FR-SO-09 | TASK-0117, TASK-0118, TASK-0121 |
| R12 | Plan/schedule/AI/duplicate | FR-SO-03..07, FR-SO-10 | TASK-0119, TASK-0120, TASK-0121 |
| R13 | Ads/boost | FR-AD-01..05 | TASK-0127, TASK-0128, TASK-0129 |
| R14 | Social data download/delete/update | FR-SO-08, FR-PR-01 | TASK-0121 |
| R15 | ChatGPT, Gemini, Claude | FR-AI-01..07, FR-SO-06, FR-I18N-04 | TASK-0123, TASK-0124, TASK-0125 |
| R16 | Packages/plans | FR-BL-01..06, FR-AD-04, FR-AI-03 | TASK-0131, TASK-0132 |
| R17 | Buyer/admin/seller panels | FR-IAM-*, FR-PNL-*, FR-RS-*, FR-MK-*, FR-AU-01, FR-SA-01 | TASK-0105, TASK-0106, TASK-0133, TASK-0134 |
| R18 | Reports | FR-RP-01, FR-RP-02, FR-SO-10, FR-AD-05 | TASK-0136 |
| R19 | Filters | FR-FL-01, FR-FL-02, FR-API-05 | TASK-0137 |
| R20 | Best visuals on Next.js | FR-API-01..04, FR-SHL-01..05, NFR-PERF-01/02, NFR-ACC-01 | TASK-0103, TASK-0104, TASK-0138 |
| R21 | Android and iOS | FR-MB-01..05, NFR-PERF-06 | TASK-0140–TASK-0143 |
| R22 | Multilingual incl. Urdu RTL | FR-I18N-01..04, NFR-I18N-01 | TASK-0104, TASK-0145, TASK-0147 |
| R23 | Demo system | FR-DEMO-01..03 | TASK-0149 |
| R24 | Pre-development docs | This SRS; TASK-0146–0148 outputs | TASK-0145–TASK-0148 |

### 7.2 Task → SRS coverage (TASK-0103..TASK-0144)

| Task | SRS IDs |
|---|---|
| TASK-0103 | FR-API-01..06 |
| TASK-0104 | FR-SHL-01..05, FR-I18N-01, FR-I18N-02, FR-API-03 |
| TASK-0105 | FR-IAM-01..04, FR-I18N-03 |
| TASK-0106 | FR-PNL-01..04, FR-PR-01, FR-AU-01, FR-SA-01 |
| TASK-0107 | Supports FR-DEMO-02 (runtime and seeder) |
| TASK-0108 | §6 PHASE-17 |
| TASK-0109 | FR-EM-01 (research) |
| TASK-0110 | FR-EM-01..03 |
| TASK-0111 | FR-EM-10, FR-EM-11, FR-PR-02 |
| TASK-0112 | FR-EM-06, FR-EM-07 |
| TASK-0113 | FR-EM-04, FR-EM-05, FR-EM-16 |
| TASK-0114 | FR-EM-08, FR-EM-09 |
| TASK-0115 | FR-EM-12..15 |
| TASK-0116 | §6 PHASE-18 |
| TASK-0117 | FR-SO-01 (research) |
| TASK-0118 | FR-SO-01, FR-SO-02 |
| TASK-0119 | FR-SO-03..05 |
| TASK-0120 | FR-SO-06, FR-SO-07 |
| TASK-0121 | FR-SO-08..10 |
| TASK-0122 | §6 PHASE-19 |
| TASK-0123 | FR-AI-01 (research) |
| TASK-0124 | FR-AI-01..03, FR-AI-07 |
| TASK-0125 | FR-AI-04..06, FR-I18N-04 |
| TASK-0126 | §6 PHASE-20 |
| TASK-0127 | FR-AD-01 (research) |
| TASK-0128 | FR-AD-01..04 |
| TASK-0129 | FR-AD-05 |
| TASK-0130 | §6 PHASE-21 |
| TASK-0131 | FR-BL-01 (research) |
| TASK-0132 | FR-BL-01..06, FR-AD-04 |
| TASK-0133 | FR-RS-01, FR-RS-02, FR-PNL-04 |
| TASK-0134 | FR-MK-01..03 |
| TASK-0135 | §6 PHASE-22 |
| TASK-0136 | FR-RP-01, FR-RP-02, FR-SA-01 |
| TASK-0137 | FR-FL-01, FR-FL-02, FR-API-05 |
| TASK-0138 | FR-SHL-04, all NFR measurements |
| TASK-0139 | §6 PHASE-23 |
| TASK-0140 | FR-MB-01 (research) |
| TASK-0141 | FR-MB-01, FR-I18N-01 |
| TASK-0142 | FR-MB-02..04 |
| TASK-0143 | FR-MB-05 |
| TASK-0144 | §6 PHASE-24 |
| TASK-0149 | FR-DEMO-01..03 |

### 7.3 Screen map → SRS coverage

| Screen map section | SRS IDs |
|---|---|
| §1 Public website, auth, onboarding | FR-SHL-04, FR-SHL-05, FR-IAM-01, FR-DEMO-03 |
| §2 Roles | §2.2, §3.11, FR-IAM-02 |
| §3 Buyer: Home | FR-SHL-01, FR-RP-01 |
| §3 Buyer: Email | FR-EM-01..16 |
| §3 Buyer: Audience | FR-EM-10..12, FR-PR-02 |
| §3 Buyer: Social | FR-SO-01..10 |
| §3 Buyer: Ads | FR-AD-01..05 |
| §3 Buyer: AI studio | FR-AI-04..06, FR-AI-03 |
| §3 Buyer: Analytics | FR-RP-01, FR-RP-02 |
| §3 Buyer: Marketplace (buy) | FR-MK-03 |
| §3 Buyer: Settings | FR-IAM-03, FR-IAM-04, FR-API-06, FR-AI-02, FR-PR-01, FR-BL-02..05, FR-AU-01 |
| §3 Notifications, search, help | FR-FL-01, FR-SHL-01, FR-AI-04 |
| §4 Super Admin panel | FR-PNL-02, FR-SA-01, FR-BL-01, FR-MK-02, FR-RS-01 |
| §5 Seller panel | FR-PNL-03, FR-PNL-04, FR-RS-01, FR-RS-02, FR-MK-01..03 |
| §6 Mobile app | FR-MB-01..05 |

## 8. Multilingual requirements summary (AC-5)
1. English (LTR) and Urdu (RTL) ship at launch. The language set is extensible through translation files only (FR-I18N-01).
2. RTL mirrors the layout:
   - the sidebar sits on the right;
   - progress and step indicators run right-to-left;
   - directional icons (arrows, chevrons, collapse) are mirrored;
   - logos, media playback controls, charts' time axes and numbers are not mirrored.
3. Fonts: Urdu headings use Noto Nastaliq Urdu; Urdu UI text uses Noto Sans Arabic; Latin text uses Plus Jakarta Sans. Line height is ≥ 1.6 for Nastaliq.
4. Formatting: dates, numbers and currency are formatted with `Intl` for the chosen locale. Users can choose Western or Eastern Arabic-Indic digits in Urdu.
5. Content: the UI language and the content language are independent. Templates, posts and AI outputs store a `locale`. Emails set the `lang` and `dir` attributes.
6. Quality: no machine-only translation for legal pages. UI copy follows the TASK-0147 bilingual tone guide. Pseudo-localization and RTL screenshots are part of the E2E suite (NFR-I18N-01).
