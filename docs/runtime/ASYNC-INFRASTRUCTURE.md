# Async Infrastructure Runtime

TASK-0004 establishes the durable infrastructure contract for VSN Marketing.

## Runtime topology

- PostgreSQL 18 is the system-of-record database and migration target.
- Redis 8 is split by logical database: general/Horizon metadata and locks on DB 0, cache on DB 1, queue payloads on DB 2.
- Horizon supervises the Laravel `redis` queue connection and listens to `default` plus `outbox`. The Redis connection name `horizon` is intentionally not defined because Horizon reserves it internally.
- S3-compatible object storage is accessed only through `ObjectStore`, implemented by Laravel Filesystem. Local/test callers can substitute a fake/local disk without changing domain code.
- MinIO is the development S3 implementation. `minio-init` creates the configured bucket before `app-init` can complete.

## Transactional outbox

Business writes that require asynchronous handoff must open a database transaction and write their business state plus `TransactionalOutbox::record()` before committing. `DatabaseTransactionalOutbox` rejects calls outside an active transaction.

`outbox:relay` claims committed rows in bounded batches using PostgreSQL row locks with `SKIP LOCKED`, writes a lease timestamp, increments a durable relay-attempt counter, then dispatches `ProcessOutboxMessage` to Redis. Stale leases are reclaimable. Rows that reach the configured relay-attempt ceiling remain durable for operator inspection rather than being silently discarded.

The queue job emits `OutboxMessageReady` before writing `published_at`. This gives **at-least-once** delivery semantics: a worker crash after downstream side effects but before `published_at` can cause replay. Every consumer must therefore deduplicate using the immutable outbox `idempotency_key` (or an equivalent durable consumer ledger). Exactly-once external delivery is not claimed.

## Worker and retry policy

- Redis queue `retry_after`: 120 seconds.
- Horizon worker timeout: 90 seconds; it stays lower than `retry_after` so a timed-out process is terminated before Redis makes the job available again.
- `ProcessOutboxMessage`: 5 tries, 60-second timeout, bounded backoff of 5s / 30s / 120s / 300s.
- Queue failures are persisted in PostgreSQL `failed_jobs` using UUIDs.
- If an outbox queue job exhausts retries, its lease is released, `last_error` is persisted, and `available_at` is deferred (default 900 seconds) so the relay can retry while preserving evidence.

## Scheduler

The scheduler runs continuously as its own service. It invokes `outbox:relay` every minute and `horizon:snapshot` every five minutes. Both schedules use cache-backed overlap locks and single-server election so horizontally scaled schedulers do not duplicate singleton work.

## Operational recovery

1. PostgreSQL remains authoritative. Never delete an unpublished outbox row to resolve a queue incident.
2. Recover Redis/Horizon, then run `php artisan outbox:relay` to reclaim eligible or stale-leased messages.
3. Inspect `failed_jobs` and `outbox_messages.last_error` before retrying failures.
4. Use `php artisan queue:retry <uuid>` only after the underlying failure is understood.
5. Horizon should be terminated gracefully during deploys (`php artisan horizon:terminate`) so the process supervisor starts workers on the new release.
6. Monitor unpublished outbox age, relay-attempt counts, failed-job growth, queue wait time, Redis health, PostgreSQL availability, and object-store errors.
