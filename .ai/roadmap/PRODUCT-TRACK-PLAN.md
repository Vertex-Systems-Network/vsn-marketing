# VSN Marketing Product Track — PHASE-17..PHASE-24 (planned)

Status: **PLANNED / NOT ACTIVE**. Owner request 2026-10-17 (repository owner, AI-only execution per ADR-0006). Baseline PHASE-00..16 is complete; this track turns the certified backend foundation into the sellable marketing product the owner specified. Activation requires the rebaseline in ADR-0007 (machine task materialization and phase-weight rebaseline). No progress percentage changes until then.

## 0. AI-native autonomy (owner decision 2026-10-17)

The product track is **fully AI-native**: AI agents plan, research, compare competitors, design data and flows, architect, design UI, engineer, test, review, merge and certify without human development, review or approval steps. AI makes all technical and product decisions inside this plan and records them as ADRs; owner decisions already given (ADR-0006, ADR-0007) are binding defaults. The only owner inputs are things AI cannot legally or physically create: third-party accounts and API keys, platform app-review submissions under the company identity, payment/ad spend accounts, app-store developer accounts, domain and hosting. Missing inputs block only that feature; AI continues with sandboxes/test modes and all other work.

Companion documents:
- [Stack operating instructions](PRODUCT-STACK-INSTRUCTIONS.md) — versions, runtime, CI and deployment rules so the stack works without breakage.
- [Screen map](PRODUCT-SCREEN-MAP.md) — landing and public pages, roles, customer dashboard and sub-screens, Super Admin and Seller panels, mobile app screens.

## 0.2 Pre-development stage (before any product code)

PHASE-17 starts with a mandatory pre-development stage. No product code task (TASK-0103 onward) may start until these are merged:

| Task | Output |
|---|---|
| TASK-0102 | Research pack: stack, panels, competitor dashboards (uses Systems/Options Banks) |
| TASK-0145 | **SRS** — requirements R1–R21 with IDs, roles, functional and measurable non-functional requirements, interfaces, acceptance criteria, traceability to tasks |
| TASK-0146 | **Architecture docs** — context/container diagrams, ERD, data-flow and sequence diagrams, `/api/v1` design, integration catalogue, ADRs |
| TASK-0147 | **UI/UX guidelines and screen specs** — tokens, components, navigation, accessibility, English/Urdu tone, spec for every screen in the screen map |
| TASK-0148 | **Engineering, QA, security and release guidelines** — coding standards, Definition of Done, test strategy, security/privacy checklist, release/rollback, demo-data rules |

Documents live under `docs/product/` (SRS, architecture, ux, guidelines) and are updated whenever later work changes them.

## 0.3 Demo system

TASK-0149 builds a full demo system: sandbox providers that never send, post, charge or spend; dummy data for every module and screen (contacts, lists, templates, campaigns with stats, social calendar and posts, AI drafts, analytics, plans, marketplace listings, reseller clients); demo users for every role; one-click reset; public "Try demo" login on the landing page. Every later phase extends the demo data for its new screens, and the demo is part of each phase certification.

## 0.4 Non-stop execution policy (AI flow fixes)

The product track runs continuously without asking the owner for routine consent. Agents merge green work, start the next dependency-ready task and only stop when all remaining work needs an owner-only external input.

Issues seen in earlier AI flow and their fixes:

| Issue seen | Fix (binding rule) |
|---|---|
| Every merge needed an extra checkpoint commit (observed-main drift), doubling CI runs | Open the PR first, then add exactly one checkpoint commit; never push state-only follow-up PRs |
| One small PR per change consumed many CI runs | Bundle a full task (or several small docs tasks) per PR; docs-only PRs use lightweight control CI |
| README progress marker went stale and failed governance | Run `python tools/readme_progress_sync.py` after every state change, before commit |
| Journal append failed at size limit mid-transition | Run `python tools/ai_journal.py compact` before each transition when the journal is near its limit |
| Stale blocker text (TASK-0024) contradicted completed state | When a task completes, reconcile `BLOCKERS.md` and related docs in the same PR |
| Scheduled supervisor ran every 5 minutes (≈288 runner jobs/day) | Reduced to every 6 hours; PR/issue events still trigger it |
| CI flake (Docker daemon timeout) | One re-run of failed jobs only; a second failure is treated as real |
| Local environment could not install PHP packages | Rely on CI as the test runner; never claim local test runs that did not happen |
| Asking the owner "continue/merge?" between tasks | Forbidden; continue automatically (AGENTS.md continuous mode) |

## 0.5 GitHub runner budget

- Verify locally first (validators, lint, PHP syntax, TypeScript) before pushing; push once per meaningful slice.
- Workflows keep `concurrency: cancel-in-progress` so superseded runs stop.
- Docs/plan-only PRs: lightweight control CI only (no `CI-Mode: full`) unless a certification requires full gates.
- Full application + security CI on code PRs and phase certifications only.
- Heavy jobs (benchmarks, mobile EAS builds, load tests) run only at phase certification or on manual dispatch.
- Scheduled workflows are kept to the minimum frequency that still serves their purpose.

## 0.6 Research banks

- [Systems Bank](../research/PRODUCT-TRACK/SYSTEMS-BANK.md): existing market systems, pricing signals, user complaints and gaps (2026-10-17).
- [Options Bank](../research/PRODUCT-TRACK/OPTIONS-BANK.md): candidate features with priority, target phase and how VSN differs.

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
| R20 | Best visuals, fully featured, sales-booster UI on Next.js | 11 basic screens (Inertia React) | P17 + every phase |
| R21 | Android and iOS apps | None | P24 |

## 2. Technology stack (owner decision 2026-10-17)

Architecture: **one Laravel API backend, three clients** (Next.js web, Android, iOS) sharing one TypeScript API client and design tokens in a monorepo. The certified PHASE-00..16 backend is kept; only the presentation layer moves.

| Layer | Technology | Why |
|---|---|---|
| Backend API | PHP 8.3+/Laravel (existing modules), versioned REST `/api/v1` + OpenAPI 3.1 spec, Laravel Sanctum (web cookie + mobile tokens), webhooks | Reuses all certified domain logic, tenancy, RBAC, billing, privacy |
| Web app | Next.js (App Router, React 19, TypeScript, Server Components) | Owner choice; SEO marketing site + app in one, best React ecosystem |
| Web UI | Tailwind CSS v4, shadcn/ui (Radix primitives), lucide icons, Framer Motion, Recharts/Tremor charts, TanStack Table, React Hook Form + Zod | Premium, accessible, sales-grade visuals |
| Web data | TanStack Query, generated typed API client from OpenAPI, next-intl (i18n incl. Urdu/RTL) | Typed end-to-end, cache, offline-friendly |
| Mobile apps | React Native + Expo (Android + iOS from one codebase), Expo Router, NativeWind, EAS Build/Submit, Expo Notifications | Shares TypeScript, API client and design tokens with web |
| Monorepo | pnpm workspaces + Turborepo: `apps/web`, `apps/mobile`, `packages/api-client`, `packages/ui-tokens`, `packages/validation`; Laravel stays at repo root | One place, shared code, cached builds |
| Data | PostgreSQL 18, Redis 8 (queues/cache), S3-compatible object storage | Existing, production-grade |
| Queues/realtime | Laravel Horizon, Laravel Reverb (WebSockets) for live tracking/notifications | Live open/click/post status |
| Search | PostgreSQL full-text first; Meilisearch if scale requires | Filters and global search (R19) |
| AI | Existing AI gateway → OpenAI, Google Gemini, Anthropic Claude adapters | Provider-neutral (R15) |
| Payments | Stripe Billing (web); Apple/Google in-app purchase only if store rules require for mobile | R16 |
| Testing | Pest/PHPUnit, Vitest + Testing Library, Playwright (web E2E), Jest + Maestro/Detox (mobile E2E), axe accessibility | QA gates |
| DevOps | Docker Compose (dev), GitHub Actions CI, Vercel or container hosting for Next.js, EAS for app stores, Sentry monitoring | Deploy and observe |

## 3. AI-native lifecycle applied to every phase

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

## 4. Phases

### PHASE-17 — Product shell, design system and panels (R17, R20)
- TASK-0102 Research: SaaS admin/panel patterns, competitor dashboards, design systems, Next.js + Expo monorepo practices; confirm stack in section 2 (ADR-0007).
- TASK-0102A API layer: versioned `/api/v1`, Sanctum auth, OpenAPI spec and generated TypeScript client; existing Inertia screens stay until replaced.
- TASK-0103 Monorepo + Next.js app shell: Turborepo, shared tokens, shadcn/ui design system, light/dark theme, sidebar/topbar, dashboard, marketing landing pages.
- TASK-0104 Roles and access: default roles (Super Admin, Owner, Admin, Marketer, Viewer), role-management screen, team invites, signup/login polish.
- TASK-0105 Panels: Super Admin (tenants, plans, usage, revenue, marketplace moderation), Customer/Buyer workspace, Seller panel (reseller/agency clients, marketplace listings, orders, payouts, affiliate earnings) over TASK-0097 delegation.
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
- TASK-0132 Reseller/agency commission and white-label billing; affiliate/referral program.
- TASK-0132A Marketplace: seller onboarding and verification, listings (templates, automation recipes, AI prompt packs, integrations, done-for-you services), reviews/ratings, buyer checkout, seller payouts via Stripe Connect, platform fee, refunds/disputes and moderation.
- TASK-0133 Certify PHASE-22 (Stripe live only after owner decision).

### PHASE-23 — Reports, filters and launch (R18, R19, R20)
- TASK-0134 Reports centre: cross-channel dashboards, scheduled reports, CSV/PDF export.
- TASK-0135 Global search and filters on every list.
- TASK-0136 Performance, security, accessibility and visual QA; production deployment runbook.
- TASK-0137 Certify product launch readiness.

### PHASE-24 — Android and iOS apps (R21)
- TASK-0138 Research: Expo/React Native current releases, App Store and Play Store policies (privacy labels, in-app purchase rules, push), competitor mobile apps.
- TASK-0139 Mobile foundation: Expo app in monorepo, auth, workspace switcher, shared API client and tokens, push notifications.
- TASK-0140 Mobile features: dashboard, campaign and post approvals, social composer/scheduler, live email/post stats, AI writer, notifications.
- TASK-0141 Store release pipeline: EAS builds, test tracks (TestFlight/Play internal), store listings.
- TASK-0142 Certify PHASE-24 (device E2E, accessibility, store-readiness).

## 5. Decisions

| Decision | Default | Status |
|---|---|---|
| Web UI | Next.js frontend over the Laravel API | Owner decision 2026-10-17 |
| Mobile | React Native + Expo, Android + iOS | Owner decision 2026-10-17 |
| "Seller panel" meaning | All seller models: (1) reseller/agency white-label with client sub-accounts and commission, (2) marketplace where sellers list templates, automation recipes, AI prompt packs, integrations and done-for-you marketing services, buyers purchase and VSN takes a platform fee, (3) affiliate/referral partners | Owner decision 2026-10-17 |
| Order | PHASE-17 first, then 18 → 24 | Default |

## 6. Owner-only external inputs (cannot be produced by AI)

Provider accounts and API keys (SendGrid, Mailchimp, OpenAI, Gemini, Anthropic…); social developer apps and platform app-review approval; ad accounts and spend authority; Stripe live account; production server and domain; Apple Developer and Google Play developer accounts. Each blocks only its feature; all other work proceeds with sandboxes/test modes.
