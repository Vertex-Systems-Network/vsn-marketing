# VSN Marketing Product Track — PHASE-17..PHASE-24 (active)

Status: **ACTIVE** (ADR-0007 accepted 2026-10-17). Owner request 2026-10-17 (repository owner, AI-only execution per ADR-0006). Baseline PHASE-00..16 is complete; this track turns the certified backend foundation into the sellable marketing product the owner specified. Tasks are materialized in `.ai/tasks/` and weights rebaselined (foundation 50%, product track 50%).

## 0. AI-native autonomy (owner decision 2026-10-17)

The product track is **fully AI-native**: AI agents plan, research, compare competitors, design data and flows, architect, design UI, engineer, test, review, merge and certify without human development, review or approval steps. AI makes all technical and product decisions inside this plan and records them as ADRs; owner decisions already given (ADR-0006, ADR-0007) are binding defaults. The only owner inputs are things AI cannot legally or physically create: third-party accounts and API keys, platform app-review submissions under the company identity, payment/ad spend accounts, app-store developer accounts, domain and hosting. Missing inputs block only that feature; AI continues with sandboxes/test modes and all other work.

Companion documents:
- [AI master prompt (Roman Urdu)](../../docs/product/AI-MASTER-PROMPT.md) — single prompt to start any AI session.
- [Stack operating instructions](PRODUCT-STACK-INSTRUCTIONS.md) — versions, runtime, CI and deployment rules so the stack works without breakage.
- [Screen map](PRODUCT-SCREEN-MAP.md) — landing and public pages, roles, customer dashboard and sub-screens, Super Admin and Seller panels, mobile app screens.

## 0.1 Pre-development stage (before any product code)

PHASE-17 starts with a mandatory pre-development stage. No product code task (TASK-0103 onward) may start until these are merged:

| Task | Output |
|---|---|
| TASK-0102 | Research pack: stack, panels, competitor dashboards (uses Systems/Options Banks) |
| TASK-0145 | **SRS** — requirements R1–R21 with IDs, roles, functional and measurable non-functional requirements, interfaces, acceptance criteria, traceability to tasks |
| TASK-0146 | **Architecture docs** — context/container diagrams, ERD, data-flow and sequence diagrams, `/api/v1` design, integration catalogue, ADRs |
| TASK-0147 | **UI/UX guidelines and screen specs** — tokens, components, navigation, accessibility, English/Urdu tone, spec for every screen in the screen map |
| TASK-0148 | **Engineering, QA, security and release guidelines** — coding standards, Definition of Done, test strategy, security/privacy checklist, release/rollback, demo-data rules |

Documents live under `docs/product/` and are updated whenever later work changes them: [SRS](../../docs/product/SRS.md), [ARCHITECTURE](../../docs/product/ARCHITECTURE.md), [API-V1](../../docs/product/API-V1.md), [UX-GUIDELINES](../../docs/product/UX-GUIDELINES.md), [SCREEN-SPECS](../../docs/product/SCREEN-SPECS.md), [ENGINEERING-GUIDELINES](../../docs/product/ENGINEERING-GUIDELINES.md).

## 0.2 Demo system

TASK-0149 builds a full demo system: sandbox providers that never send, post, charge or spend; dummy data for every module and screen (contacts, lists, templates, campaigns with stats, social calendar and posts, AI drafts, analytics, plans, marketplace listings, reseller clients); demo users for every role; one-click reset; public "Try demo" login on the landing page. Every later phase extends the demo data for its new screens, and the demo is part of each phase certification.

## 0.3 Non-stop execution policy (AI flow fixes)

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


Additional binding rules (from the owner master prompt, `docs/product/AI-MASTER-PROMPT.md`):

- **Stop only when** all executable work is done, or every remaining item waits on an owner-only external input (section 6). Re-check main, open PRs and CI before stopping.
- **Report format:** short Roman Urdu summary — what merged (PR links), what was tested, what remains, and at most one clear owner request. Never claim "done" for work that did not run or failed.
- **Safety:** no spam, consent/suppression bypass, provider-limit evasion, fake accounts or unauthorized scraping; secrets never committed or logged; strict tenant isolation; nothing is sent, posted or spent live without the customer's in-product approval; live money/ads/production only by owner decision.
- **Precedence:** repository files (AGENTS.md, this plan, task registry) override the prompt when they differ; update the prompt when the plan changes.

## 0.4 GitHub runner budget

- Verify locally first (validators, lint, PHP syntax, TypeScript) before pushing; push once per meaningful slice.
- Workflows keep `concurrency: cancel-in-progress` so superseded runs stop.
- Docs/plan-only PRs: lightweight control CI only (no `CI-Mode: full`) unless a certification requires full gates.
- Full application + security CI on code PRs and phase certifications only.
- Heavy jobs (benchmarks, mobile EAS builds, load tests) run only at phase certification or on manual dispatch.
- Scheduled workflows are kept to the minimum frequency that still serves their purpose.

## 0.5 Research banks

- [Systems Bank](../research/PRODUCT-TRACK/SYSTEMS-BANK.md): existing market systems, pricing signals, user complaints and gaps (2026-10-17).
- [Options Bank](../research/PRODUCT-TRACK/OPTIONS-BANK.md): candidate features with priority, target phase and how VSN differs.

## 1. Owner requirements (source of truth)

| # | Requirement | Current baseline evidence | Track phase | Tasks |
|---|---|---|---|---|
| R1 | Send emails | Delivery engine (PHASE-04), live sending disabled | P18 | TASK-0110, TASK-0113 |
| R2 | Integrate SMTP, Google SMTP, SendGrid, Mailchimp and other free+paid providers | Provider/connector framework (PHASE-03/14), not live | P18 | TASK-0109, TASK-0110 |
| R3 | List provider templates | Provider template sync partial (TASK-0035) | P18 | TASK-0112 |
| R4 | Show provider sending limits | Quota/rate controls (TASK-0021) | P18 | TASK-0110 |
| R5 | Sent / delivered / opened / clicked status | Email engagement extension EM-01..06 unweighted, not built | P18 | TASK-0114 |
| R6 | Build own templates | Template/content model (PHASE-06), no editor UI | P18 | TASK-0112 |
| R7 | Manage email address lists | Contacts/lists/tags (PHASE-02), no UI | P18 | TASK-0111 |
| R8 | Analyse active/disabled addresses | Suppression/deliverability (PHASE-05) | P18 | TASK-0111 |
| R9 | Email tracking | as R5 | P18 | TASK-0114 |
| R10 | Email-marketing sub-options (campaigns, automation, A/B, segments) | PHASE-07/08/09/11 backend | P18 | TASK-0113, TASK-0115 |
| R11 | Social media posting management | Social adapters offline (PHASE-13) | P19 | TASK-0118, TASK-0119 |
| R12 | Plan, schedule, post, AI-assisted posts, duplicate check across networks | Calendar/scheduler backend (PHASE-07) | P19 | TASK-0119, TASK-0120 |
| R13 | Integrate social ad/boost systems | Not built | P21 | TASK-0128, TASK-0129 |
| R14 | Social account data download/delete/update | Contact-only privacy controls (TASK-0096) | P19 | TASK-0121 |
| R15 | ChatGPT, Gemini, Claude integration | AI gateway offline (PHASE-10) | P20 | TASK-0124, TASK-0125 |
| R16 | Package/plan system | Plans/metering, Stripe test mode (TASK-0098) | P22 | TASK-0132 |
| R17 | Buyer, admin, seller panels | No panels | P17 (+P22) | TASK-0105, TASK-0106, TASK-0133, TASK-0134 |
| R18 | Reports | Analytics backend + 1 screen (PHASE-12) | P23 | TASK-0136 |
| R19 | Filters | Segments only | P23 | TASK-0137 |
| R20 | Best visuals, fully featured, sales-booster UI on Next.js | 11 basic screens (Inertia React) | P17 + every phase | TASK-0104, TASK-0138 |
| R21 | Android and iOS apps | None | P24 | TASK-0141–0143 |
| R22 | Multilingual UI incl. Urdu (RTL) | None | P17 + all | TASK-0145, TASK-0147, TASK-0104 |
| R23 | Demo system with dummy data | Partial E2E seeders | P17 + all | TASK-0149 |
| R24 | Pre-development SRS, architecture, UX and engineering docs | None | P17 | TASK-0145–0148 |

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

## 2.1 Design source (owner decision 2026-10-17)

- **Design workspace:** all new product designs (web dashboards, panels, mobile screens) are created in Figma team `1468195760558609504`, folder `637889245`. Product file: [VSN Marketing — Product App](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN) (variable collection `VSN Tokens`).
- **Screens:** see `docs/product/DESIGN-INDEX.md`.
- **Color/token source:** Figma file `JqukmwKhVyObh1SQ6gsKR0` ("VSN Website Redesign — 1920", page `🎨 Design System`). Tokens are copied from that file into the product file and into `packages/ui-tokens`; code never hard-codes hex values and uses token names only.

| Token | Value |
|---|---|
| brand gradient | `#625ba8` → `#6188c6` (55%) → `#5ac8d6` |
| gradient hover / pressed | `#544d96/#5277b4/#47b5c3` / `#47418a/#4a6ba3/#3e9fad` |
| brand/white, brand/ink, brand/ink-2 | `#ffffff`, `#000000`, `#3f4245` |
| text/muted | `#7e8083` |
| surface/cloud, surface/field, surface/night | `#ebedf4`, `#f4f5f9`, `#1a1c20` |
| line/light | `#e2e4eb` |
| state/error | `#d14b5a` |
| focus | 3px `#5ac8d6` (buttons); inputs 1.5px `#625ba8` + 4px ring `rgba(98,91,168,.18)` |
| disabled | opacity .38 (buttons), .55 (fields) |
| font | Plus Jakarta Sans (600 labels, 400 body), line-height 1.2 |
| radius | pill 999px; field 14px |
| button sizes | heights 58/48/40, label 16/15/14 |

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

## 4. Phases and tasks (generated from `.ai/tasks/INDEX.yaml`; registry is the source of truth)

### PHASE-17 — Pre-development, API, Next.js shell, roles, panels, demo (R17, R20, R22, R23)
- **TASK-0102** Research product shell, panels and Next.js/Expo monorepo stack _(after TASK-0100)_ — Verify current Next.js, shadcn/ui, Expo and Turborepo practices and benchmark competitor dashboards and panels.
- **TASK-0145** Write Software Requirements Specification (SRS) _(after TASK-0102)_ — IEEE 29148-style SRS for the product track: scope, personas/roles, functional requirements R1-R21 with IDs, non-functional requirements (security, privacy, performance, accessibility, availability), external interfaces, constraints and acceptance criteria.
- **TASK-0146** Write architecture, data model, data flow and API documentation _(after TASK-0145)_ — System context and container diagrams, module boundaries, ERD for new entities, data-flow and sequence diagrams for email, social, AI, ads, billing and marketplace, OpenAPI design and integration catalogue.
- **TASK-0147** Write UI/UX guidelines and screen specifications _(after TASK-0146)_ — Design principles, tokens, component inventory, layout/navigation, accessibility rules, content tone (English and Urdu), and per-screen specs with states for every screen in PRODUCT-SCREEN-MAP.
- **TASK-0148** Write engineering, QA, security and release guidelines _(after TASK-0147)_ — Coding standards for PHP/TypeScript, branching/PR rules, test strategy and Definition of Done, security and privacy checklist, release/rollback and demo-data rules, aligned with PRODUCT-STACK-INSTRUCTIONS.
- **TASK-0103** Implement versioned API layer and OpenAPI client _(after TASK-0148)_ — Expose existing modules through /api/v1 with Sanctum auth, OpenAPI 3.1 spec and generated TypeScript client.
- **TASK-0104** Implement Next.js app shell and design system in monorepo _(after TASK-0103)_ — Create pnpm/Turborepo monorepo with Next.js web, shared tokens and shadcn/ui design system, navigation and dashboard.
- **TASK-0105** Implement default roles, role management, invites and signup _(after TASK-0104)_ — Ship default roles, role screen, team invites and signup on the existing permission catalog.
- **TASK-0106** Implement Super Admin, Buyer and Seller panels _(after TASK-0105)_ — Deliver the three panels with tenant isolation, reseller/agency client management and marketplace/affiliate placeholders.
- **TASK-0107** Fix developer runtime and add demo seeder _(after TASK-0106)_ — Fix Docker Compose (PostgreSQL 18 volume, MinIO image, single composer install, Vite host) and add vsn:demo seeder.
- **TASK-0149** Implement demo system with dummy data _(after TASK-0107)_ — Demo mode with sandbox providers (no real sending/posting/spend), seeded dummy data for every module (contacts, lists, templates, campaigns with stats, social posts and calendar, AI drafts, analytics, billing plans, marketplace listings, reseller clients), demo users for every role, one-click reset, public demo login on the landing page.
- **TASK-0108** Certify PHASE-17 _(after TASK-0149)_ — Certify product shell, API and panels.

### PHASE-18 — Email marketing live (R1–R10)
- **TASK-0109** Research email providers, limits, tracking and competitor email builders _(after TASK-0108)_ — Verify SMTP, Gmail, SendGrid, Mailchimp, Brevo, Mailgun, SES, Resend, Postmark, Mailjet APIs, free tiers, webhooks and competitor builders.
- **TASK-0110** Implement live email provider connections, limits and failover _(after TASK-0109)_ — Connect providers with credential vault, connection tests, limit meters and automatic failover.
- **TASK-0111** Implement contacts and lists UI with health analysis _(after TASK-0110)_ — CSV import, dedupe, tags, lists and active/bounced/suppressed health screens.
- **TASK-0112** Implement template studio and provider template import _(after TASK-0111)_ — Drag-and-drop editor, own templates, provider template listing/import, preview and test send.
- **TASK-0113** Implement campaign wizard and sending _(after TASK-0112)_ — Audience, content, schedule, approval and send through selected provider.
- **TASK-0114** Implement email engagement tracking _(after TASK-0113)_ — Delivered, bounce, open, click, unsubscribe and complaint via webhooks, pixel and safe redirect with machine-open/bot-click separation.
- **TASK-0115** Implement email analytics, automation, A/B and deliverability screens _(after TASK-0114)_ — Operator screens over existing journeys, experiments and deliverability modules.
- **TASK-0116** Certify PHASE-18 _(after TASK-0115)_ — Certify live email marketing.

### PHASE-19 — Social media management (R11, R12, R14)
- **TASK-0117** Research social network APIs, policies and competitor schedulers _(after TASK-0116)_ — Verify Meta, LinkedIn, X, TikTok, YouTube, Pinterest APIs, app review and limits.
- **TASK-0118** Implement social account connections _(after TASK-0117)_ — OAuth connect, token refresh and account health.
- **TASK-0119** Implement composer, calendar, scheduling and approvals _(after TASK-0118)_ — Per-network previews, shared calendar with email, queue and approvals.
- **TASK-0120** Implement AI post assistant and duplicate detection _(after TASK-0119)_ — AI post generation and near-duplicate detection across networks and history.
- **TASK-0121** Implement social account data controls, inbox and performance sync _(after TASK-0120)_ — Account data download/update/delete, inbox and post metrics.
- **TASK-0122** Certify PHASE-19 _(after TASK-0121)_ — Certify social media management.

### PHASE-20 — AI providers live (R15)
- **TASK-0123** Research OpenAI, Gemini and Claude APIs, pricing and data use _(after TASK-0122)_ — Verify current model APIs, pricing, data retention and regions.
- **TASK-0124** Implement live AI provider adapters with BYOK and cost limits _(after TASK-0123)_ — Adapters behind the AI gateway, platform and bring-your-own keys, usage and cost caps.
- **TASK-0125** Implement AI writer and assistants _(after TASK-0124)_ — Email copy, subjects, social posts, rewrite/translate incl. Urdu, brand voice.
- **TASK-0126** Certify PHASE-20 _(after TASK-0125)_ — Certify live AI providers.

### PHASE-21 — Ads and boosting (R13)
- **TASK-0127** Research ads APIs and boost policies _(after TASK-0126)_ — Verify Meta Marketing, Google Ads, TikTok Ads, LinkedIn Ads APIs and review.
- **TASK-0128** Implement ad accounts and boost-a-post with spend caps _(after TASK-0127)_ — Connect ad accounts and boost posts with human-approved budgets and hard caps.
- **TASK-0129** Implement ad results reporting _(after TASK-0128)_ — Organic, paid and email results in one view.
- **TASK-0130** Certify PHASE-21 _(after TASK-0129)_ — Certify ads and boosting.

### PHASE-22 — Packages, billing, reseller, affiliate and marketplace (R16, R17)
- **TASK-0131** Research SaaS pricing, Stripe Billing/Connect, tax, reseller and marketplace models _(after TASK-0130)_ — Verify competitor pricing, Stripe Billing and Connect, tax and marketplace rules.
- **TASK-0132** Implement plans, limits, trials and invoices _(after TASK-0131)_ — Free/Starter/Pro/Agency plans on the TASK-0098 engine with self-serve upgrade and cancellation.
- **TASK-0133** Implement reseller commission and affiliate program _(after TASK-0132)_ — Reseller/agency white-label billing, commissions and affiliate referral tracking.
- **TASK-0134** Implement marketplace _(after TASK-0133)_ — Seller onboarding, listings (templates, recipes, prompt packs, integrations, services), reviews, checkout, Stripe Connect payouts, refunds and moderation.
- **TASK-0135** Certify PHASE-22 _(after TASK-0134)_ — Certify packages, billing and marketplace.

### PHASE-23 — Reports, filters and launch (R18, R19, R20)
- **TASK-0136** Implement reports centre with export and schedules _(after TASK-0135)_ — Cross-channel dashboards, scheduled reports, CSV/PDF export.
- **TASK-0137** Implement global search and filters _(after TASK-0136)_ — Search and saved filters on every list.
- **TASK-0138** Run launch QA and deployment runbook _(after TASK-0137)_ — Performance, security, accessibility and visual QA plus production deployment runbook.
- **TASK-0139** Certify product launch readiness _(after TASK-0138)_ — Certify the web product.

### PHASE-24 — Android and iOS apps (R21)
- **TASK-0140** Research Expo, store policies and competitor mobile apps _(after TASK-0139)_ — Verify Expo/React Native releases, App Store and Play policies, in-app purchase and push rules.
- **TASK-0141** Implement mobile foundation _(after TASK-0140)_ — Expo app in monorepo with auth, workspace switcher, shared API client and push.
- **TASK-0142** Implement mobile features _(after TASK-0141)_ — Dashboard, approvals, composer/scheduler, live stats, AI writer, notifications.
- **TASK-0143** Implement store release pipeline _(after TASK-0142)_ — EAS builds, TestFlight/Play internal tracks, listings.
- **TASK-0144** Certify PHASE-24 _(after TASK-0143)_ — Certify mobile apps.

## 5. Decisions

| Decision | Default | Status |
|---|---|---|
| Web UI | Next.js frontend over the Laravel API | Owner decision 2026-10-17 |
| Mobile | React Native + Expo, Android + iOS | Owner decision 2026-10-17 |
| "Seller panel" meaning | All seller models: (1) reseller/agency white-label with client sub-accounts and commission, (2) marketplace where sellers list templates, automation recipes, AI prompt packs, integrations and done-for-you marketing services, buyers purchase and VSN takes a platform fee, (3) affiliate/referral partners | Owner decision 2026-10-17 |
| Order | PHASE-17 first, then 18 → 24 | Default |

## 6. Owner-only external inputs (cannot be produced by AI)

Provider accounts and API keys (SendGrid, Mailchimp, OpenAI, Gemini, Anthropic…); social developer apps and platform app-review approval; ad accounts and spend authority; Stripe live account; production server and domain; Apple Developer and Google Play developer accounts. Each blocks only its feature; all other work proceeds with sandboxes/test modes.
