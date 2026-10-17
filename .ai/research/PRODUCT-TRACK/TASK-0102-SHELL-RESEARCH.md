# TASK-0102 — Product shell, panels and Next.js/Expo monorepo research

Date: 2026-10-17 · Author: AI Supervisor · Status: complete (inputs to TASK-0145..0148)

Rules: every fact has a source. Anything not confirmed from an official page is marked **unverified**. Exact patch versions are pinned during TASK-0104, after `npm view <pkg> version` has checked them.

## 1. Stack verification (AC-2)

| Item | Finding (2026-10-17) | Source | Decision |
|---|---|---|---|
| Next.js | 16.3 announced 2026-08-03. 16.x is LTS through 2027; 15.x reaches EOL around 2026-10-21. Security release covered 16.3/15.5. | [nextjs.org/blog](https://nextjs.org/blog?page=1), [versionlog](https://versionlog.com/nextjs/) | Use **Next.js 16.3.x** (App Router). Never 15.x. Patch version **unverified**. |
| Expo | SDK 56 (2026-05-21) ships React Native 0.85 and React 19.2. The changelog points to SDK 57 to fix a Hermes memory regression. | [expo.dev/changelog/sdk-56](https://expo.dev/changelog/sdk-56.md), [expo.dev/sdk/56](https://expo.dev/sdk/56) | Use the newest SDK that is confirmed on expo.dev (56, or 57 once confirmed). SDK 57 details are **unverified**. |
| React | 19.2 is shared by Next 16 and RN 0.85 | Expo SDK 56 changelog | Use one React version across the workspace. |
| Monorepo | No official Turborepo example combines Next and Expo. The community reference is create-t3-turbo (Next + Expo, Tailwind v4 via NativeWind v5, pnpm + Turborepo). Vercel's official Turborepo example covers Next.js only. | [create-t3-turbo README](https://cdn.jsdelivr.net/gh/t3-oss/create-t3-turbo@main/README.md), [Vercel monorepo template](https://www.zeit.co/templates/next.js/monorepo-turborepo) | Follow the create-t3-turbo **layout** pattern. Do not copy its tRPC; the API stays Laravel REST + OpenAPI. |
| Styling | Tailwind v4 on web and NativeWind v5 on mobile. Both read the same tokens. | as above | Confirms §2 of the plan. |

**Result:** PRODUCT-TRACK-PLAN §2 is **confirmed** with one change: version pins are Next 16.3.x and Expo SDK ≥ 56. No new ADR is needed, because ADR-0007 already covers the stack and the pins are recorded here.

## 2. Monorepo layout

```
apps/web          Next.js 16 App Router (marketing site + app + panels)
apps/mobile       Expo Router app (Android + iOS)
packages/api-client   generated from Laravel OpenAPI 3.1 (openapi-typescript + fetch)
packages/ui-tokens    tokens.json → tailwind @theme CSS, NativeWind preset, TS constants
packages/validation   Zod schemas shared by web and mobile
(Laravel stays at repo root; /api/v1)
```

## 3. Design-system scope (AC-3)

The token source is Figma file `JqukmwKhVyObh1SQ6gsKR0`. They are mirrored into the product file [VSN Marketing — Product App](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN), which holds 14 variables in the `VSN Tokens` collection. The token table is in PRODUCT-TRACK-PLAN §2.1.

`packages/ui-tokens` spec:
- Single source file `tokens.json`. Its names match the Figma variables, e.g. `brand/gradient-start`, `surface/cloud`, `radius/pill`.
- The build emits three outputs:
  - `tokens.css`, a Tailwind v4 `@theme` block with `--vsn-*` variables (matching the Figma WEB code syntax `var(--vsn-...)`);
  - `nativewind-preset.js`;
  - `tokens.ts`.
- A CI check fails the build if a raw hex value appears in `apps/**` outside token files.
- Starting component inventory, taken from the Figma Design System page, maps onto shadcn/ui primitives: Button, Text Link, Chip, Badge, Breadcrumb, Nav Item, Input, Select, Textarea, Checkbox, Radio, Toggle, Accordion, Tabs/Filter, Tooltip, Toast, Avatar, Feature/Step Card, Section Heading, Header, Footer, Mobile Menu.

## 4. Competitor dashboard shells (AC-1, from SYSTEMS-BANK)

| Product | Shell pattern | Take for VSN |
|---|---|---|
| HubSpot | Top nav by hub with a global search bar; object index tables with saved views | Saved filter views on every list (R19) |
| Mailchimp | Left nav (Campaigns, Audience, Automations, Analytics); campaign wizard checklist | Checklist-style campaign builder |
| Brevo | Left nav with a multi-channel switcher; sender/plan usage visible | Live provider-limit meter in the header (R4) |
| Hootsuite | Planner calendar + streams | Calendar as the social home screen (R11) |
| Buffer | Queue-per-channel with minimal chrome | Simple per-account queue view |
| Sprout Social | Unified inbox + reports | Shared report builder (R18) |

These patterns are qualitative observations from public product pages and the help docs listed in SYSTEMS-BANK. Feature-level claims there keep their own sources. UI details not re-checked today are **unverified**.

## 5. Panels, navigation and data flow (AC-3)

- **One Next.js app with route groups:**
  - `(marketing)`: public site.
  - `(auth)`.
  - `(app)`: Buyer/customer workspace.
  - `(admin)`: Super Admin.
  - `(seller)`: Reseller/Agency, Marketplace vendor, Affiliate.
- **Shell:** collapsible left sidebar with role-filtered nav; top bar with workspace switcher, global search, usage meter, notifications and profile; RTL mirroring for Urdu.
- **Role gating:** the server reads `/api/v1/me` (roles and permissions from the existing RBAC) in the layout. Nav items and routes are filtered server-side, and the API stays the authority. Middleware redirects unauthorized route groups.
- **Data flow:**
  1. The browser sends a request to Next.js.
  2. A Server Component calls the Laravel `/api/v1` through `packages/api-client` (Sanctum cookie).
  3. TanStack Query handles client mutations.
  4. Reverb WebSocket pushes live tracking and post status.
  5. Mobile uses the same client with Sanctum tokens.
- **Demo:** a `DEMO_MODE` tenant uses sandbox providers (TASK-0149).

## 6. Hand-off

TASK-0145 (SRS) uses §§3–5 for its role and screen requirements. TASK-0147 creates screen pages in the Figma product file, using the tokens from §3.
