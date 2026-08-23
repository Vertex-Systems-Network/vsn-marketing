# Infrastructure Runtime

TASK-0004 establishes the first durable runtime contracts for VSN Marketing. Business modules depend on Core contracts; framework drivers stay in Core Infrastructure.

## Runtime defaults

| Concern | Runtime | Contract |
| --- | --- | --- |
| Persistence | PostgreSQL 18 | Laravel database connection behind repositories |
| Cache | Redis 8 DB 1 | Laravel cache store |
| Distributed locks | Redis 8 DB 3 | `DistributedLock` |
| Queue | Redis 8 DB 2 | Laravel queue + Horizon |
| Object storage | S3-compatible / MinIO locally | `ObjectStore` |
| Async durability | PostgreSQL outbox + Redis publish jobs | `OutboxRepository` / `OutboxTransport` |

Tests may substitute SQLite, array cache, sync queues, and local/fake filesystems. Those substitutes do not change production contracts.

## Queue and Horizon policy

- Redis queue writes use `after_commit=true`; jobs cannot observe database state that has not committed.
- Horizon owns the `default` and `outbox` queues.
- Outbox publish jobs have five attempts with bounded backoff: 5s, 30s, 120s, 300s.
- Failed queue jobs are stored with UUIDs in PostgreSQL `failed_jobs`.
- Redis uses AOF (`appendonly yes`, `appendfsync everysec`) in the developer runtime.
- Deployments should run `php artisan horizon:terminate` after new code is available so supervisors restart cleanly.
- Operators may inspect failed jobs with `php artisan queue:failed`, retry with `php artisan queue:retry <uuid>`, and forget a resolved failure with `php artisan queue:forget <uuid>`.

## Transactional outbox

A domain/application transaction records an `outbox_messages` row in the same PostgreSQL transaction as its state mutation. The scheduler executes `outbox:dispatch` every minute under a distributed Redis lock. It enqueues `PublishOutboxMessage` jobs but does not mark rows published.

A publish job:

1. reloads the pending row,
2. invokes the `OutboxTransport`,
3. marks `published_at` only after transport success,
4. increments `attempts` and records `last_error` when publication throws,
5. relies on the queue retry/failure policy for bounded retries.

Duplicate scanner runs are safe: publish jobs are unique by outbox message ID, and already-published rows are no-ops.

## Object storage

Production uses the S3-compatible `s3` disk. Local Docker Compose uses MinIO and an initialization container that creates the configured bucket. Application code uses `ObjectStore`, not AWS or MinIO SDKs directly. Tests use Laravel's local/fake disk.

Storage errors are configured to throw. Silent loss of campaign assets, exports, or generated artifacts is not acceptable.

## Developer runtime

```bash
cp .env.example .env
docker compose up --build
```

Services:

- `app`: Laravel HTTP runtime
- `horizon`: Redis queue supervisor
- `scheduler`: Laravel scheduler / outbox scanner
- `postgres`: PostgreSQL 18
- `redis`: Redis 8 with AOF
- `minio`: S3-compatible storage
- `minio-init`: idempotent bucket initialization
- `vite`: React/Vite development server

## Integration verification

`Application Foundation CI` runs an `integration` job with PostgreSQL 18 and Redis 8 service containers and executes:

```bash
php artisan test --testsuite=Integration
```

The integration suite verifies PostgreSQL 18, migrations, Redis cache/locks/queue behavior, Horizon configuration, and durable outbox handoff.
