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
