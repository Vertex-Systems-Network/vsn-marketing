# VSN Marketing — Engineering, QA, security and release guidelines

| Field | Value |
|---|---|
| Version | 1.0 — 2026-10-17 |
| Owner task | TASK-0148 |
| Binding with | [PRODUCT-STACK-INSTRUCTIONS](../../.ai/roadmap/PRODUCT-STACK-INSTRUCTIONS.md) (versions, runtime, CI cost), [AGENTS.md](../../AGENTS.md), [07-CODING-STANDARDS](../../.ai/07-CODING-STANDARDS.md), [08-TESTING-STANDARDS](../../.ai/08-TESTING-STANDARDS.md), [09-DEFINITION-OF-DONE](../../.ai/09-DEFINITION-OF-DONE.md), [12-QUALITY-ENGINEERING-GATES](../../.ai/12-QUALITY-ENGINEERING-GATES.md) |
| Inputs | [SRS](SRS.md), [ARCHITECTURE](ARCHITECTURE.md), [API-V1](API-V1.md), [UX-GUIDELINES](UX-GUIDELINES.md), [SCREEN-SPECS](SCREEN-SPECS.md) |

If this document conflicts with `AGENTS.md` or the `.ai/` standards, those win. This file adds product-track specifics on top of them.

## 1. Coding standards

### 1.1 PHP / Laravel
- PHP 8.3+, `declare(strict_types=1)`, final classes by default, constructor-promoted readonly properties.
- Module layout follows ARCHITECTURE §3: `Domain` / `Application` / `Infrastructure` / `Http`. Controllers stay thin: validate, authorize, call a use case, return an API resource.
- Every tenant model uses the tenant scope. Raw queries must include `tenant_id`. No `withoutGlobalScopes()` outside Admin use cases that are audited.
- Money uses integer minor units and a currency value object; never floats.
- External calls go only through adapter interfaces, with a Sandbox twin. No SDK calls in controllers or models.
- Tools: Pint (PSR-12 plus the repo preset), PHPStan at the repo baseline level (no new baseline entries), Pest.

### 1.2 TypeScript (web, mobile, packages)
- `strict: true`, `noUncheckedIndexedAccess`; no `any` without an `// eslint-disable-next-line` comment giving a reason.
- Data comes only from `packages/api-client` (generated). Forms use React Hook Form + Zod schemas from `packages/validation`.
- Styling uses Tailwind with token classes from `packages/ui-tokens`. Hex literals fail lint (custom ESLint rule `no-raw-color`).
- Server Components by default; `"use client"` only for interactive leaves. No secrets in client bundles (`NEXT_PUBLIC_` holds public values only).
- Every user-visible string goes through next-intl or the mobile i18n layer, in both English and Urdu files. A missing key fails CI.
- Accessibility: Radix/shadcn primitives; no clickable `div`s; images need `alt`.
- Tools: ESLint (next, react-hooks, jsx-a11y, import), Prettier, Vitest + Testing Library.

### 1.3 Naming and structure
- API fields use `snake_case`; TS variables use `camelCase` (the generated client maps between them).
- Routes:
  - Next.js: `app/(marketing)` for the website, `app/(auth)` for sign-in flows, `app/(panel)/{buyer|admin|seller}` for the panels.
  - Expo: Router groups mirror the mobile screen map.
- Feature flags are named `area.feature` (e.g. `social.tiktok_publish`) and default off in production.

## 2. Branching, commits and PRs

1. Branches follow the session's designated branch. Use one PR per task slice; a PR should be small enough to review in one pass (target ≤ 600 changed lines excluding generated files).
2. Commit messages: imperative subject ≤ 72 chars, a body explaining why, and the attribution trailers required by the session.
3. Every PR carries exactly one checkpoint made with `tools/ai_txn.py checkpoint`, with observed main and the active PR. State changes go only through `ai_txn.py`.
4. Merge with squash and `expectedHeadSha`, then merge `origin/main` back into the branch. Never force-push shared history, and never skip or disable a test to get green.
5. Docs/plan PRs run lightweight control CI. Code PRs run the full gates (§4). Certification PRs add the heavy jobs.

## 3. Definition of Done (testable)

A slice is done only when every applicable item below has evidence in the PR (CI run links, test names or screenshots).

| # | Criterion | Evidence |
|---|---|---|
| DoD-1 | The acceptance criteria of the task, and the SRS IDs it implements, are met | Tests named after the SRS ID, e.g. `it('FR-EM-02 warns at 80% of provider limit')` |
| DoD-2 | Unit, feature and adversarial tests exist for new logic; coverage on changed lines is ≥ 80% (NFR-MNT-01) | Coverage report in CI |
| DoD-3 | Tenant isolation: the cross-tenant suite covers every new model and endpoint (NFR-SEC-03) | `tests/Security/CrossTenant*` passing |
| DoD-4 | Authorization: each endpoint has allow and deny tests for the SRS §3.11 roles it touches | Policy tests |
| DoD-5 | Money and send/publish/spend paths have idempotency, cap and approval tests (NFR-SEC-05) | Adversarial tests |
| DoD-6 | API: OpenAPI is updated, the client is regenerated, the contract tests pass and the schema diff introduces no breaking change | CI `openapi-diff` |
| DoD-7 | UI: matches the Figma frame from DESIGN-INDEX; every state in SCREEN-SPECS is implemented; English and Urdu strings exist; there is an RTL screenshot | Playwright screenshots (LTR + RTL) |
| DoD-8 | Accessibility: axe shows 0 serious or critical issues on new screens; keyboard path tested | Playwright + axe |
| DoD-9 | Performance budgets hold for touched key pages (NFR-PERF-01/02) | Lighthouse CI or trace |
| DoD-10 | Demo data is extended for the new screens, and the sandbox adapter is used in demo tenants (FR-DEMO-01/02) | Seeder test |
| DoD-11 | Docs are updated (SRS, ARCHITECTURE, API-V1, SCREEN-SPECS or the troubleshooting registry) where behaviour changed | Diff |
| DoD-12 | Security checklist (§5) is answered for the slice; no secrets in the diff (secret scan clean) | PR checklist + CI |
| DoD-13 | AI continuity validators pass and the checkpoint is recorded | `ai_state.py`, `ai_journal.py`, `ai_policy.py`, `supervisor_contract.py` |

## 4. QA strategy mapped to CI gates

| Level | Scope | Tool | CI gate (job) | When |
|---|---|---|---|---|
| Static | PHP style, types; TS lint, types; token/i18n lint | Pint, PHPStan, ESLint, `tsc --noEmit`, i18n key check | `foundation` (PHP), `web-static` (TS) | Every code PR |
| Unit | Domain rules, value objects, hooks, utils | Pest, Vitest | `foundation`, `web-unit` | Every code PR |
| Feature / API | Endpoints, policies, validation, problem errors | Pest (HTTP) | `foundation` | Every code PR |
| Contract | OpenAPI schema vs implementation; client freshness | spectator/openapi validator, `openapi-diff` | `api-contract` | Every code PR touching the API |
| Integration | PostgreSQL 18 + Redis 8 behaviour, queues, locks, FTS | Pest on service containers | `integration` | Code PRs touching persistence or queues; certification |
| Adversarial / security | Cross-tenant, privilege escalation, cap bypass, replayed webhooks, SSRF in URLs | Pest security suites | `security-gates` | Every code PR |
| SAST / dependencies / secrets | Code scanning, vulnerable packages, leaked secrets | CodeQL, `composer audit`, `npm audit`, secret scan | `codeql-*`, `dependency-audit`, `secret-scan` | Code PRs; weekly schedule |
| E2E web | Critical flows: sign up, onboarding, campaign send (sandbox), connect social, compose and schedule, checkout (Stripe test), RTL smoke | Playwright | `e2e` | Code PRs touching UI; certification |
| Accessibility | axe on every E2E page; keyboard flows | @axe-core/playwright | `e2e` (a11y step) | Same as E2E |
| Visual | LTR + RTL screenshots of panel templates | Playwright snapshots | `e2e` (visual step) | UI PRs |
| Performance | Lighthouse budgets; API k6 load; send throughput | Lighthouse CI, k6, benchmark | `perf` (manual/certification) | Phase certification |
| Mobile | Unit (Jest), E2E (Maestro) on Android emulator / iOS simulator | Jest, Maestro, EAS build | `mobile` | Mobile PRs (unit); certification (E2E, builds) |
| Container | Image scan, compose config validation, dev smoke | Trivy, `docker compose config`, `tools/dev_smoke` | `container-scan`, `integration` | Changes to Docker files; certification |
| Governance | AI state/journal/policy validity, action pinning | repo validators | `governance`, `action-integrity` | Every PR |

Release gating:
- **Phase certification** runs every gate above on the exact head and on the resulting main.
- **Launch (TASK-0139)** also requires all NFR targets measured and met (SRS §6).

## 5. Security and privacy checklist (per slice)

1. **Tenancy.** Is every new query, cache key, file path, queue payload and broadcast channel tenant-scoped?
2. **Authorization.** Does a policy check run on every endpoint and job? Is self-approval blocked? Are custom roles prevented from escalating?
3. **Input.** Do Form Request rules cover every field? Are file uploads type- and size-checked and virus-scan ready? Do URL fields pass the SSRF guard (no private IP ranges)?
4. **Secrets.** Are credentials stored in the vault and masked after save? Are they absent from logs, exceptions and analytics? Are no new keys in the repo?
5. **Webhooks.** Is the signature verified, the replay window ≤ 5 min, processing idempotent and queued?
6. **Money.** Are idempotency keys, cap checks under row lock, ledger entries and audit events all in place?
7. **Messaging.** Do sends check consent and suppression? Is unsubscribe present? Is the complaint/bounce auto-pause respected?
8. **AI.** Is PII excluded unless enabled? Is the cost cap checked before the call? Is output never auto-sent?
9. **Privacy.** Is new personal data added to the export and erasure jobs and to the retention schedule (NFR-PRV-02)?
10. **Headers and web.** Are CSP, HSTS, `SameSite=Lax` cookies and CSRF in place? Is there no HTML injection (sanitized template rendering)?
11. **Audit.** Do security- and money-relevant actions write audit events?
12. **Dependencies.** Is every new package justified, maintained, licence-compatible (MIT/Apache/BSD) and pinned?

## 6. Release and rollback

| Step | Rule |
|---|---|
| Versioning | Calendar versions `YYYY.MM.patch` for web and API; mobile follows semver with build numbers |
| Environments | local → CI → staging → production. Staging uses sandbox adapters and Stripe test mode |
| Migrations | Expand → migrate → contract; every migration has a `down` or a documented forward fix; take a backup before production migrations |
| Deploy order | API (backward compatible) → workers → web → mobile OTA. Feature flags gate new features; nothing goes live (C-02) without the owner switch |
| Go-live of money/sending | Owner-only decision, recorded in an ADR or decision log entry; first live send uses the canary tenant and limits |
| Rollback | Web: redeploy the previous build. API: redeploy the previous image; if a migration was contract-phase, use the forward fix. Mobile: an EAS Update rollback to the previous channel. Queue: pause the affected queues in Horizon |
| Verification | Smoke tests after deploy (health endpoint, login, one panel render, one sandbox send); watch error rate for 30 min |
| Incidents | Severity levels S1–S3; status page update within 15 min for S1; postmortem within 5 days |

## 7. Demo-data rules

1. Demo tenants are flagged `is_demo` and always resolve Sandbox adapters. A demo tenant can never hold live credentials.
2. Dummy data is synthetic:
   - names come from a fixed fake list;
   - emails use `example.com`/`example.org`;
   - phone numbers use reserved test ranges;
   - images come from the licensed image library in Figma or are generated.

   No real customer data, ever.
3. Every module seeds data for every screen and state in SCREEN-SPECS, including empty, error and limit-reached examples behind toggles.
4. Twelve demo users, one per role (SRS §2.2), share a documented demo password. Demo login is public, but each session gets an isolated tenant that expires after 24 h.
5. Reset is idempotent: `php artisan vsn:demo --reset` rebuilds the tenant in under 60 s.
6. Seeders are tested (row counts per module, no live adapters bound) in CI.

## 8. Documentation upkeep

When behaviour changes, update the matching document in the same PR:

| Change | Document |
|---|---|
| Requirement | SRS |
| Module, data model or flow | ARCHITECTURE (with an ADR if a decision changes) |
| Endpoint | API-V1 and `openapi.yaml` |
| Screen or state | Figma, DESIGN-INDEX and SCREEN-SPECS |
| Runtime problem | Troubleshooting registry in PRODUCT-STACK-INSTRUCTIONS §7 |
