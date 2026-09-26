# Week-1 Shipping Mode


## Development Acceleration v2.7 overlay

- Batch dependency-ready, disjoint worker leases into one wave-control carrier whenever distinct real agents are available; do not create a protected-main lease PR for each sibling lane by default.
- An independent leased lane may code from the last green `ship/week-1` baseline while a newer sibling integration head is still under CI. It may not submit or consume that pending change until the latest required green integration baseline is synchronized.
- Merge alerts remain mandatory, but independent lanes synchronize at the next submission/dependency-consumption boundary instead of stopping an in-progress owned-path batch solely because a sibling merged.
- Worker PR validation and `ai_parallel.py sync-check` use `ship/week-1` as the worker baseline while Shipping Mode is active; Supervisor/main-only governance still uses protected `main`.
- Consolidate terminal worker evidence into the next wave-control/promotion boundary. Full protected-main Application + Security gates remain mandatory for promotion/final acceptance and security-sensitive exceptions.
Status: ACTIVE when the integration branch `ship/week-1` exists and this plan is referenced by the active Supervisor.

Purpose: compress development feedback loops for a seven-day production-candidate sprint without weakening the final `main` release boundary.

## Branch topology

```text
main
└── ship/week-1
    ├── lane/backend
    ├── lane/frontend
    ├── lane/delivery
    ├── lane/data
    └── lane/qa-release
```

Feature/workstream PRs for the sprint target `ship/week-1`. `main` remains protected and receives only promotion PRs from `ship/week-1` after integration evidence is green.

## Five writable lanes

1. Backend — domain/application services, APIs, validation and persistence.
2. Frontend — Inertia/React UX and user-facing workflows.
3. Delivery — provider adapters, queues, orchestration, retry/idempotency and send state.
4. Data — events, reporting, practical dashboard and data-quality checks.
5. QA/Release — integration, E2E, regression, staging, migration rehearsal and release evidence.

The Supervisor owns shared contracts, workflows, migrations that cross lanes, global state and merge order. Read-only research/review agents may run in parallel but do not receive overlapping write leases.

### Activation-time drain exception

`TASK-0026` was already active with pre-created, occupied workstreams before Shipping Mode was activated. Those existing workstreams are grandfathered only long enough to drain safely; terminating them solely to force the five-writer target would discard or duplicate in-flight work. No new writable slot may be added or reassigned above five while the grandfathered task drains. After `TASK-0026` transitions, five concurrent writable implementation lanes is the hard Shipping Mode cap; additional agents are read-only reviewers/researchers unless capacity is explicitly freed inside those five lanes.

## CI tiers

### Tier 1 — PR Fast Gate

Every PR targeting `ship/week-1` must pass `Shipping Fast Gate` before merge. It runs continuity/policy validation, immutable-action checks, security-exception validation, Composer validation, PHP formatting, PHP static analysis, Unit + Feature tests, TypeScript typecheck, frontend unit tests and dependency audits.

A worker must run the equivalent affected checks before push. A known failing fast gate is not a valid submission.

### Tier 2 — Integration Wave

Every push/merge to `ship/week-1` runs the full `Application Foundation CI` and `AI Continuity Guard`, including the PHP compatibility floor, PostgreSQL/Redis integration suite, frontend build and Playwright smoke. A failed integration wave freezes only the affected dependency chain; independent lanes may continue if they do not consume the broken contract.

### Tier 3 — Main Promotion / Release

A PR from `ship/week-1` to `main` must satisfy the existing protected-main required checks: `foundation`, `php-floor`, `integration`, `e2e`, `security-gates` and `governance`. Full Security Supply Chain CI remains a main-promotion boundary. No required main check is removed or weakened.

## Failure escalation

### Level 1 — Normal

Formatting, lint/static analysis, Unit/Feature tests and frontend typecheck/unit tests. Fix on the same lane before submission.

### Level 2 — Elevated

Changes touching migrations, authentication/authorization, shared contracts, queues, delivery eligibility, provider/security policy, workflow files or other shared paths require extra integration evidence before dependent lanes consume the change.

### Level 3 — Stop the affected line

If a merge wave exposes data corruption risk, migration failure, cross-module regression, critical security failure, duplicate delivery, broken idempotency or an equivalent production-impacting fault, freeze that dependency chain. Reproduce, add a failing regression test, fix, rerun the relevant integration evidence, then resume. Do not freeze unrelated lanes.

## Merge-wave rule

Do not merge every small PR directly to `main`. Merge green sprint PRs into `ship/week-1`, then let the integration branch absorb and validate the wave. Promote to `main` only from a green integration baseline.

Recommended waves:

- Wave A: contracts, schema/shared backend prerequisites.
- Wave B: backend/frontend/delivery vertical slice implementation.
- Wave C: analytics, E2E, regression and release hardening.

## Definition of done for the sprint

A slice is done when a user can perform it, data persists correctly, expected failure is handled, minimum automated coverage exists, and the integrated result works on the shipping baseline. Generalized abstractions, optional providers, advanced analytics, cosmetic polish and non-blocking documentation are Week-2 work unless they are required for safety or acceptance.

## Scope freeze

For Week-1, do not put the following on the critical path unless they block the production-candidate flow: framework upgrades, architecture rewrites, speculative abstractions, advanced automation builders, nonessential provider integrations, sophisticated billing, advanced A/B testing, deep attribution and cosmetic-only polish.

## Daily release discipline

Day 1: shipping mechanics, CI, scope freeze and current-wave cleanup.
Day 2: audience/contact vertical slice.
Day 3: template/campaign vertical slice.
Day 4: delivery/orchestration vertical slice.
Day 5: events/reporting/minimal dashboard.
Day 6: integration, permissions, failure states, staging and bug bash; no architecture expansion.
Day 7: feature freeze, full regression/security/migration/rollback validation and release candidate promotion.

## Safety invariant

Shipping Mode changes where expensive checks run, not whether they exist. Fast feedback happens before merge to `ship/week-1`; full application integration happens on the integration branch; full protected-main and security evidence remains mandatory before release promotion.
