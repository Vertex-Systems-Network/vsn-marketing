# ADR-0008: Product-track architecture decisions

- Status: Accepted
- Date: 2026-10-17
- Task: TASK-0146
- Context: [SRS](../../docs/product/SRS.md), [ARCHITECTURE](../../docs/product/ARCHITECTURE.md), [API-V1](../../docs/product/API-V1.md), ADR-0007

## Decisions

| # | Decision | Reason | Alternatives rejected |
|---|---|---|---|
| D1 | Keep the certified Laravel modules as the only backend. Next.js and Expo are API clients only | Reuses certified tenancy, RBAC, delivery and billing logic | Next.js server actions talking to the DB (duplicates the domain, breaks isolation) |
| D2 | Versioned REST `/api/v1` with OpenAPI 3.1 and a generated TypeScript client | Typed contract for 3 clients; CI schema diff | GraphQL (heavier caching and auth surface for this team size) |
| D3 | Sanctum: SPA cookie for web, bearer tokens for mobile; tenant selected by the `X-Workspace-Id` header and checked against membership | First-party auth with no OAuth server to run | Passport/OAuth2 server |
| D4 | pnpm + Turborepo monorepo (`apps/web`, `apps/mobile`, `packages/*`) with Laravel at the repo root | Shared tokens, client and validation | Separate repos |
| D5 | New modules: Social, Ads, Marketplace, Demo, Notifications, Search. Billing, Partners, Identity, Providers, AI and Analytics are extended in place | Clear ownership per SRS area | One "product" module |
| D6 | Every external integration sits behind a provider-neutral adapter with a Sandbox twin, selected by the adapter registry | Enforces C-02 (nothing live without owner) and demo mode | Feature flags inside adapters |
| D7 | Money paths (checkout, boost, wallet, payout, refund) use idempotency keys, row locks on caps, append-only ledgers and signed webhooks | NFR-SEC-05: zero over-cap spend | Trust provider-side limits only |
| D8 | Real-time UI updates through Reverb private tenant/user channels carrying thin events | Live tracking under 10 s (NFR-PERF-05) | Polling only |
| D9 | Search starts with PostgreSQL FTS (`search_documents`); Meilisearch only if NFR targets fail | Less infrastructure | Elasticsearch |
| D10 | Duplicate post detection with 64-bit simhash on text and perceptual hash on media, Hamming distance ≤ 6 | Cheap and explainable | Embedding similarity (adds AI cost per post) |
| D11 | Credentials in an envelope-encrypted vault with per-tenant data keys; never returned after save | NFR-SEC-02 | Laravel `encrypted` cast with the app key only |
| D12 | i18n lives in the clients (next-intl, i18n for Expo); the API returns keys and locale-formatted values only where needed | One translation source per client; RTL handled in the UI | Server-rendered translated strings |

## Consequences

- TASK-0103 builds D2 and D3 first. Every later task adds endpoints to API-V1 and updates the OpenAPI document in the same PR.
- New tables follow the ARCHITECTURE §4 invariants. The cross-tenant test suite is extended for every new model.
- Any change to a decision in this ADR needs a new ADR that supersedes it.
