# VSN Marketing Product Track — PHASE-17..PHASE-23 (planned)

Status: **PLANNED / NOT ACTIVE**. Owner request 2026-10-17 (repository owner, AI-only execution per ADR-0006). Baseline PHASE-00..16 is complete; this track turns the certified backend foundation into the sellable marketing product the owner specified. Activation requires the rebaseline in ADR-0007 (machine task materialization and phase-weight rebaseline). No progress percentage changes until then.

## 1. Owner requirements (source of truth)

| # | Requirement | Current baseline evidence | Track phase |
|---|---|---|---|
| R1 | Send emails | Delivery engine (PHASE-04), live sending disabled | P18 |
| R2 | Integrate SMTP, Google SMTP, SendGrid, Mailchimp and other free+paid providers | Provider/connector framework (PHASE-03/14), not live | P18 |
| R3 | List provider templates | Provider template sync partial (TASK-0035) | P18 |
| R4 | Show provider sending limits | Quota/rate controls (TASK-0021) | P18 |
| R5 | Sent / delivered / opened / clicked status | Email engagement extension EM-01..06 unweighted, not built | P18 |
| R6 | Build own templates | Template/content model (PHASE-06), no editor UI | P18 |
| R7 | Manage email address lists | Contacts/lists/tags (PHASE-02), no UI | P18 |
| R8 | Analyse active/disabled addresses | Suppression/deliverability (PHASE-05) | P18 |
| R9 | Email tracking | as R5 | P18 |
| R10 | Email-marketing sub-options (campaigns, automation, A/B, segments) | PHASE-07/08/09/11 backend | P18 |
| R11 | Social media posting management | Social adapters offline (PHASE-13) | P19 |
| R12 | Plan, schedule, post, AI-assisted posts, duplicate check across networks | Calendar/scheduler backend (PHASE-07) | P19 |
| R13 | Integrate social ad/boost systems | Not built | P21 |
| R14 | Social account data download/delete/update | Contact-only privacy controls (TASK-0096) | P19 |
| R15 | ChatGPT, Gemini, Claude integration | AI gateway offline (PHASE-10) | P20 |
| R16 | Package/plan system | Plans/metering, Stripe test mode (TASK-0098) | P22 |
| R17 | Buyer, admin, seller panels | No panels | P17 (+P22) |
| R18 | Reports | Analytics backend + 1 screen (PHASE-12) | P23 |
| R19 | Filters | Segments only | P23 |
| R20 | Best visuals, fully featured, sales-booster UI (Next.js requested) | 11 basic screens | P17 + every phase |

## 2. AI-native lifecycle applied to every phase

Each phase runs these stages in order, by AI, with repository evidence for each (the owner's requested flow):

1. **Plan** — phase scope, task list, acceptance criteria, dependencies.
2. **Search & research** — current official API/docs, pricing, limits, policies (Research-First Standard, `.ai/research/PHASE-xx/`).
3. **Market & competitor comparison** — feature/UX/pricing benchmark against named competitors (e.g. Mailchimp, Brevo, HubSpot, Klaviyo, ActiveCampaign for email; Buffer, Hootsuite, Sprout Social, Later, Metricool for social; Jasper/Copy.ai for AI writing).
4. **Data design** — entities, tables, migrations, retention, tenant keys.
5. **Data flow** — sequence of events/queues/webhooks between modules and providers.
6. **Architecture** — module boundaries, adapters, ADRs for any change.
7. **UI/UX design** — screens, navigation, states, accessibility (WCAG 2.2 AA), visual design.
8. **Engineering** — implementation in small PR slices.
9. **QA** — unit, feature, adversarial, PostgreSQL/Redis integration, Playwright E2E, accessibility, performance.
10. **Finalization** — certification matrix, exact-head + resulting-main gates, README/progress update.

## 3. Phases

### PHASE-17 — Product shell, design system and panels (R17, R20)
- TASK-0102 Research: SaaS admin/panel patterns, competitor dashboards, design systems; decide UI stack (ADR-0007: keep Laravel + Inertia React, add shadcn/ui-style component system; Next.js only if a separate public site/app is later required).
- TASK-0103 Design system and app shell: tokens, light/dark theme, sidebar/topbar navigation, dashboard, empty/loading/error states.
- TASK-0104 Roles and access: default roles (Super Admin, Owner, Admin, Marketer, Viewer), role-management screen, team invites, signup/login polish.
- TASK-0105 Panels: Super Admin (tenants, plans, usage, revenue), Customer/Buyer workspace, Seller/Reseller/Agency panel over TASK-0097 delegation.
- TASK-0106 Developer runtime: demo seeder (`vsn:demo`), Docker fixes (PostgreSQL 18 volume, MinIO image, single composer install, Vite host).
- TASK-0107 Certify PHASE-17.

### PHASE-18 — Email marketing live (R1–R10)
- TASK-0108 Research: SMTP/Gmail SMTP (OAuth/app passwords), SendGrid, Mailchimp Transactional, Brevo, Mailgun, Amazon SES, Resend, Postmark — free tiers, limits, template and webhook APIs; competitor email builders.
- TASK-0109 Provider connections live: credential vault, connection test, per-provider limit display and enforcement.
- TASK-0110 Contacts and lists UI: import (CSV), dedupe, tags, active/disabled/suppressed analysis.
- TASK-0111 Template studio: drag-and-drop editor, own templates, provider template listing/import, preview/test send.
- TASK-0112 Campaign wizard and sending: audience, content, schedule, approvals, send through selected provider.
- TASK-0113 Engagement tracking (absorbs EM-01..06): delivered/bounce/open/click/unsubscribe via provider webhooks, pixel and safe click redirect, bot/privacy-proxy flags.
- TASK-0114 Email analytics dashboard and automation/A-B/segment screens.
- TASK-0115 Certify PHASE-18.

### PHASE-19 — Social media management (R11, R12, R14)
- TASK-0116 Research: Meta (Facebook/Instagram), LinkedIn, X, TikTok, YouTube, Pinterest APIs, app-review, rate limits; competitor schedulers.
- TASK-0117 Account connections: OAuth connect, token refresh, account health.
- TASK-0118 Composer, content calendar, scheduling, approvals, per-network previews.
- TASK-0119 AI post assistant and duplicate/near-duplicate detection across networks and history.
- TASK-0120 Social account data download/update/delete and post performance sync.
- TASK-0121 Certify PHASE-19.

### PHASE-20 — AI providers live (R15)
- TASK-0122 Research: OpenAI, Google Gemini, Anthropic Claude APIs, pricing, data use, regions.
- TASK-0123 Live provider adapters behind the existing AI gateway, platform key and bring-your-own-key, usage/cost limits.
- TASK-0124 AI writer features: email copy, subject lines, social posts, rewrite/translate, brand voice.
- TASK-0125 Certify PHASE-20.

### PHASE-21 — Ads and boosting (R13)
- TASK-0126 Research: Meta Marketing API, Google Ads, TikTok Ads, LinkedIn Ads — boost flows, review, spend controls.
- TASK-0127 Ad account connections and boost-a-post with human-approved budgets and hard spend caps.
- TASK-0128 Ad results reporting.
- TASK-0129 Certify PHASE-21.

### PHASE-22 — Packages, billing and reseller commerce (R16, R17)
- TASK-0130 Research: SaaS pricing tiers of competitors, Stripe Billing, tax, reseller models.
- TASK-0131 Plans and limits (Free/Starter/Pro/Agency), upgrade/downgrade, trials, invoices on the TASK-0098 engine.
- TASK-0132 Reseller/seller commission and white-label billing.
- TASK-0133 Certify PHASE-22 (Stripe live only after owner decision).

### PHASE-23 — Reports, filters and launch (R18, R19, R20)
- TASK-0134 Reports centre: cross-channel dashboards, scheduled reports, CSV/PDF export.
- TASK-0135 Global search and filters on every list.
- TASK-0136 Performance, security, accessibility and visual QA; production deployment runbook.
- TASK-0137 Certify product launch readiness.

## 4. Decisions defaulted by AI (owner may override)

| Decision | Default | Status |
|---|---|---|
| UI stack | Keep Laravel + Inertia React; premium component system; no Next.js rewrite | ADR-0007 PROPOSED |
| "Seller panel" meaning | Reseller/agency selling the tool to its clients (not a goods marketplace) | Assumption, confirm |
| Order | PHASE-17 first, then 18 → 23 | Default |

## 5. Owner-only external inputs (cannot be produced by AI)

Provider accounts and API keys (SendGrid, Mailchimp, OpenAI, Gemini, Anthropic…); social developer apps and platform app-review approval; ad accounts and spend authority; Stripe live account; production server and domain. Each blocks only its feature; all other work proceeds with sandboxes/test modes.
