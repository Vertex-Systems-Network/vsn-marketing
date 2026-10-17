# Product stack operating instructions (PHASE-17..24)

Binding for every AI agent working on the product track. Goal: the stack (Laravel API, Next.js web, Expo mobile, PostgreSQL, Redis, storage) installs, runs, builds and deploys without breakage. Each rule is enforced by CI where marked **[CI]**.

## 1. Versions and compatibility

1. Pin exact versions: `composer.lock`, `pnpm-lock.yaml`, `.nvmrc`/`engines` (Node 24 LTS), `packageManager` field (pnpm), PHP `^8.3` with CI on 8.3 and 8.5. **[CI]** fails on lockfile drift.
2. Before adding or upgrading any package, check official release notes and peer-dependency compatibility (React 19 ↔ Next.js ↔ Expo SDK ↔ React Native). Expo SDK dictates the React/React Native versions for mobile; web and mobile may use different React minors only if the shared packages stay framework-agnostic.
3. Docker images use explicit major tags (`postgres:18-alpine`, `redis:8-alpine`, `node:24-*`); never `latest`. Images must be pullable without login. **[CI]** compose config validation.
4. Dependabot/renovate updates are grouped, verified by full CI and merged only when green.

## 2. Monorepo layout

```
/ (Laravel API, existing)      apps/web (Next.js)      apps/mobile (Expo)
packages/api-client (generated from OpenAPI)   packages/ui-tokens   packages/validation (Zod)
```
- Shared packages contain no framework-specific code except where named.
- The API client is regenerated from `openapi.yaml` on every API change; a stale client fails **[CI]**.

## 2.1 Design source and tokens (owner decision 2026-10-17)

- **Design workspace:** all new product designs (web dashboards, panels, mobile screens) are created in Figma team `1468195760558609504`, folder `637889245`. Product file: [VSN Marketing — Product App](https://www.figma.com/design/5lMnkndlKLHunSFGBJYhDN) (variable collection `VSN Tokens`).
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

## 3. Local runtime (must work on Windows, macOS, Linux)

- `docker compose up -d` brings up postgres, redis, object storage, app, horizon, scheduler and web with no manual edits.
- PostgreSQL 18 volume mounts at `/var/lib/postgresql`; only one service runs `composer install`/`pnpm install`; others wait on it.
- Host ports are configurable via `.env` (`APP_PORT`, `DB_PORT`, `REDIS_PORT`, `WEB_PORT`) with defaults that avoid common conflicts (Laragon/XAMPP).
- Vite/Next dev servers bind to `0.0.0.0` inside containers but advertise `localhost` to browsers.
- `php artisan vsn:demo` seeds demo tenant, roles and users; documented in README.
- A smoke script (`tools/dev_smoke`) checks every service, migrations, login and one page render. **[CI]** runs it on the compose stack.

## 4. API contract rules

- All client traffic uses `/api/v1`; breaking changes need `/api/v2`. Errors use RFC 9457 problem JSON.
- Auth: Sanctum SPA cookies for web, personal access tokens with expiry for mobile; every endpoint tenant scoped and permission checked. **[CI]** contract tests.
- Webhooks in (providers) are signature-verified, idempotent and queued.

## 5. Quality gates for every PR

Pint, PHPStan, Pest (SQLite + PostgreSQL/Redis integration), ESLint, TypeScript strict, Vitest, Playwright (web), Jest + Maestro (mobile), axe accessibility, Lighthouse budgets on key pages, OpenAPI diff, security scans (CodeQL, secret scan, dependency audit, container scan). No skipped or weakened tests to get green.

## 6. Deployment and environments

- Environments: local, CI, staging, production. Config only via environment variables; secrets only in the platform secret store.
- Migrations are backward compatible (expand → migrate → contract); backup before production migrations.
- Web: container or Vercel; API: container with Horizon and scheduler; mobile: Expo EAS builds and OTA updates with version gating.
- Observability: structured logs, Sentry (web, API, mobile), health endpoints, queue lag alerts.
- Rollback runbook for API, web and mobile OTA.

## 7. Troubleshooting registry

Every runtime problem found during development (e.g. PostgreSQL 18 volume path, unavailable MinIO image, parallel composer installs, port 8000 reserved on Windows, Vite `0.0.0.0` asset URLs, `/auth/login` path) is recorded in `docs/operations/TROUBLESHOOTING.md` with cause and fix, and a regression check is added where possible.
