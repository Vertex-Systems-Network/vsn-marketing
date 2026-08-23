# ADR-0001 — Initial Application Stack and Bootstrap Architecture

- Status: Accepted
- Date: 2026-08-23
- Governing task: TASK-0002
- Scope: Initial application/runtime stack and PHASE-01 bootstrap only

## Context

VSN Marketing is an AI-native, provider-agnostic marketing operating system. The repository charter requires a modular monolith with event-driven boundaries, canonical domain contracts, a relational primary store, durable asynchronous execution, object storage, independently scalable workers, deterministic policy gates, and no direct provider-SDK coupling in business modules.

The initial stack must optimize for correctness, operability, rapid product iteration, strong queue/event support, multi-tenant security, rich React interfaces, and a clean path to API/mobile clients without prematurely splitting the system into microservices.

## Runtime and deployment constraints

1. Production and CI are Linux-first. Application processes must not depend on Windows-specific behavior.
2. The application ships as one repository and one logical modular monolith. Web, queue, scheduler, realtime, and compiler roles may run as separate processes/containers from the same codebase.
3. The primary database must support transactions, JSON documents, robust indexing, row-level query patterns, event/outbox workloads, and future vector/search extensions.
4. Business events must not rely on queue durability alone. Database transaction + outbox is the source-of-truth handoff to asynchronous workers.
5. Redis is infrastructure for queues, cache, rate limiting, locks, and ephemeral coordination; canonical business state remains in PostgreSQL.
6. Production object storage is S3-compatible. Local development may use MinIO or Laravel storage fakes.
7. External providers are adapters. No framework choice may leak provider SDKs into domain/application code.
8. First-party browser UI and public/API surfaces coexist. Inertia is a presentation transport, not a domain boundary.
9. Dynamic template compilation and AI work must execute outside latency-sensitive HTTP request paths.
10. The baseline must run locally through Docker Compose / Laravel Sail, while native PHP/Node/PostgreSQL/Redis development remains supported.
11. Kubernetes, microservices, ClickHouse, OpenSearch, Octane, and a separate vector database are not baseline requirements. They require measured need plus a later ADR.

## Options considered

### A. Laravel modular monolith + React/Inertia

Strengths:
- Laravel provides mature transactions, queues, events, policies, scheduling, broadcasting, rate limiting, testing, and operational tooling in one application model.
- Laravel 13 officially supports PHP 8.3–8.5.
- The official React starter uses React 19, TypeScript, Inertia 3, Tailwind 4, shadcn/ui, and Vite.
- Horizon and Reverb fit the queue/realtime needs without introducing another application framework.
- One deployable codebase matches the repository charter while dedicated process roles still scale independently.

Trade-offs:
- Inertia couples the first-party browser UI to Laravel routing.
- API/mobile clients still need explicit versioned API controllers/resources.
- PHP and Node remain two runtime/tooling ecosystems.

### B. Laravel API + independently routed React SPA

Strengths:
- Strong browser/API separation.
- Frontend can deploy independently and mirrors future mobile/API consumption.

Trade-offs:
- Duplicates routing/auth/data-contract work before it is required.
- Adds CORS/session/token/cache invalidation concerns during the foundation phase.
- Increases bootstrap surface without improving domain boundaries.

### C. TypeScript end-to-end (Node/NestJS + React)

Strengths:
- One primary language across backend/frontend.
- Strong TypeScript ecosystem for AI and realtime integrations.

Trade-offs:
- More application-level assembly for queues, policies, scheduler, migrations, and admin operations.
- Less aligned with the existing Laravel-oriented operational workflow.
- No material provider-neutrality advantage because provider isolation is an architectural contract, not a language feature.

## Decision

Choose **Option A**: Laravel 13 modular monolith with React 19 + TypeScript through the official Inertia 3 application model.

### Locked baseline

- Backend framework: Laravel 13
- PHP compatibility floor: PHP 8.3
- Reference production PHP: PHP 8.5
- Dependency manager: Composer 2
- Browser application: React 19 + TypeScript, strict mode
- Browser transport: Inertia 3 for first-party console pages
- Asset pipeline: Vite
- UI primitives: Tailwind 4 + shadcn/ui as editable primitives, not a product-design constraint
- Node runtime/tooling: Node.js 24 LTS
- JavaScript package manager: npm with committed lockfile
- Primary database: PostgreSQL 18, always current supported patch
- Queue/cache/locks/rate limits: Redis 8.x
- Queue operations: Laravel Queue + Horizon
- Realtime: Laravel Reverb / broadcasting where bidirectional realtime is required; SSE is preferred for one-way AI streaming when simpler
- Object storage: Laravel Filesystem against S3-compatible storage; MinIO/fakes for local/test
- Authentication: Laravel/Fortify-based first-party auth; Sanctum for versioned API/mobile/token use
- Authorization: canonical VSN workspace-scoped RBAC implemented in-domain with Laravel policies/gates; no third-party package owns the permission model
- Backend tests: Pest 4 on PHPUnit 12
- Static/backend quality: PHPStan/Larastan, Laravel Pint
- Frontend tests: Vitest + React Testing Library
- End-to-end tests: Playwright
- CI: GitHub Actions with PostgreSQL and Redis service dependencies
- Email/template compilation: canonical TemplateCompiler port; MJML/Node adapter executes asynchronously and is never called directly by domain code

Patch versions are pinned by lockfiles/container digests during bootstrap and updated through Dependabot. Architecture is locked at the major/runtime-policy level here so routine patch updates do not require a new ADR.

## Application boundary rules

1. `app/Modules/<Module>/Domain` contains entities, value objects, domain events, domain services, and domain contracts. It has no HTTP, queue-driver, database-driver, provider-SDK, or UI dependency.
2. `app/Modules/<Module>/Application` contains use cases/actions, commands/queries, DTOs, and orchestration over domain contracts.
3. `app/Modules/<Module>/Infrastructure` contains Eloquent persistence, queue/bus adapters, external infrastructure adapters, and framework implementations of module contracts.
4. `app/Modules/<Module>/Presentation` contains web/API controllers, requests, resources, and Inertia composition for that module.
5. `app/Shared` is intentionally small and may contain only stable cross-module primitives/contracts. It must not become a dumping ground.
6. Concrete communication/marketing/mailbox provider integrations remain under the repository-standard `connectors/<provider>/...` structure.
7. Cross-module calls use explicit application/domain contracts or canonical events. Direct access to another module's infrastructure internals is forbidden.
8. Controllers, jobs, listeners, and React pages stay thin; business policy lives in domain/application code.
9. High-impact AI/provider execution continues through the existing deterministic AI/policy gates.

## Bootstrap folder target

```text
app/
  Modules/
    Core/
      Domain/
      Application/
      Infrastructure/
      Presentation/
    Identity/          # created when the module starts
    Tenancy/           # created when the module starts
    RBAC/              # created when the module starts
  Shared/
    Domain/
    Application/
    Infrastructure/
bootstrap/
config/
database/
  factories/
  migrations/
  seeders/
resources/
  js/
    components/
      ui/
    features/
    hooks/
    layouts/
    lib/
    pages/
    types/
routes/
  web.php
  api.php
  console.php
tests/
  Unit/
  Feature/
  Integration/
  Architecture/
  Contract/
connectors/
  <provider>/
```

Empty module trees must not be generated for all future modules. A module directory is created when its first governed task starts.

## Data and asynchronous execution rules

- PostgreSQL transactions are authoritative for domain state.
- Every externally visible async handoff that must not be lost uses an outbox record committed in the same transaction as the domain change.
- Workers consume typed jobs/events with idempotency keys.
- Redis queue loss/restart must not erase canonical business intent; replay originates from durable database/outbox state.
- Queue names are traffic-class aware from the start (`default`, `webhooks`, `ai`, and later delivery-specific queues) without hard-coding providers.
- Dead-letter/retry behavior is deterministic and observable.
- JSONB is allowed for extensible attributes/event payloads, but core relational invariants remain normalized.
- PostgreSQL full-text search is the initial search engine. OpenSearch is deferred.
- pgvector may be enabled when an AI retrieval use case exists; a separate vector database is deferred.
- ClickHouse is deferred until measured analytics volume justifies extraction.

## Deployment topology

Reference production roles:

```text
web             Laravel HTTP application
queue-general   Laravel/Horizon workers
queue-ai        bounded AI workers
scheduler       single Laravel scheduler role
realtime        Reverb when realtime features are enabled
template-compile asynchronous MJML compiler role/worker
postgres        managed PostgreSQL 18
redis           managed Redis 8.x
object-store    managed S3-compatible storage
```

The process roles may scale independently, but they remain one modular-monolith application and share the same domain/application code.

## Development environment

Canonical reproducible development uses Docker Compose / Laravel Sail with PostgreSQL, Redis, and optional MinIO/Mailpit services. Native development is supported when compatible PHP, Composer, Node, PostgreSQL, and Redis services are installed. No application behavior may differ by local operating system.

## Testing and quality gate

Every PHASE-01 change must keep the existing AI continuity guard green and add application tests appropriate to the layer changed.

Minimum bootstrap gates:
- Laravel boot/health feature test
- PostgreSQL integration test
- Redis queue/cache integration test
- module-boundary architecture test
- tenant-isolation tests once tenancy exists
- backend static analysis and formatting
- TypeScript typecheck
- frontend unit smoke
- Playwright critical smoke once authenticated UI exists
- no real external provider calls in required CI

## Consequences

Positive:
- Fast first-party product development with a single deployable application.
- Strong queue/realtime/authorization/testing primitives.
- PostgreSQL becomes a durable base for customer/event/consent/outbox data.
- API/mobile paths stay available without forcing a separate frontend deployment.
- Provider independence remains architectural rather than framework-specific.

Costs:
- PHP and Node toolchains must both be maintained.
- Inertia pages must not become a shortcut around API/domain boundaries.
- Redis operations require outbox/idempotency discipline for durable business workflows.
- Module boundaries need architecture tests because PHP namespaces alone do not enforce them.

## Deferred decisions

The following are explicitly not selected by this ADR:
- microservice decomposition
- Kubernetes
- Laravel Octane
- ClickHouse
- OpenSearch
- dedicated vector database
- multi-region active-active topology
- provider-specific SDK choices
- final billing provider
- final AI model/provider routing implementation

Each requires its own evidence and ADR when the relevant task/scale appears.

## Authoritative references checked on 2026-08-23

- Laravel 13 support policy: https://laravel.com/docs/master/releases
- Laravel 13 React starter kit: https://laravel.com/docs/13.x/starter-kits
- Laravel 13 upgrade/testing dependencies: https://laravel.com/docs/13.x/upgrade
- React latest major/minor documentation: https://react.dev/versions
- Node.js release status: https://nodejs.org/en/about/previous-releases
- PostgreSQL versioning/current releases: https://www.postgresql.org/support/versioning/
- Redis current documentation: https://redis.io/docs/latest/
