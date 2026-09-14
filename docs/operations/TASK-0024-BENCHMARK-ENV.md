# TASK-0024 Production-Representative Benchmark Environment

## Purpose

This runbook defines the isolated non-production environment used to capture the external evidence required by TASK-0024 AC-4. It does not define or approve numeric SLO thresholds, does not certify external provider/network latency, and does not activate PHASE-05.

The environment must execute an exact repository commit using the immutable runtime in `docker/benchmark/Dockerfile`, dedicated PostgreSQL, and dedicated Redis. Benchmark capture remains an explicit operator action through `tools/task0024_benchmark_capture.php`.

## Source pinning

The benchmark source SHA is the exact `main` commit selected after the benchmark runtime, hosted source-attestation, capture-attestation, and this runbook correction are merged. Do not hard-code an earlier control/runtime baseline as the measured source.

Railway's Docker source archive intentionally does not provide repository `.git` metadata inside the running benchmark image. For Railway-hosted execution, use the immutable deployment commit metadata supplied by Railway and require it to match the explicit benchmark source variable before any measurement:

```bash
SOURCE_SHA="$RAILWAY_GIT_COMMIT_SHA"
printf '%s' "$SOURCE_SHA" | grep -Eq '^[0-9a-fA-F]{40}$'
test "$SOURCE_SHA" = "$TASK0024_BENCHMARK_SOURCE_SHA"
printf '%s\n' "$SOURCE_SHA"
```

The value must equal the SHA supplied to `--commit-sha`. The benchmark entrypoint and capture tool both fail closed on Railway unless `TASK0024_BENCHMARK_SOURCE_SHA`, `RAILWAY_GIT_COMMIT_SHA`, and the explicit capture `--commit-sha` are full 40-character SHAs and exactly equal. Never fabricate a `.git` directory or synthetic ref to bypass this check.

For local/non-Railway execution only, a real Git checkout is required and the source may be resolved with:

```bash
SOURCE_SHA="$(git rev-parse HEAD)"
```

The capture tool independently requires that local checkout HEAD equal `--commit-sha`.

## Runtime contract

The runner must provide:

- PHP 8.5 CLI with `pcntl`, `pdo_pgsql`, `mbstring`, `bcmath`, `zip`, and `phpredis`;
- locked Composer production dependencies installed inside the image;
- repository source copied into `/workspace` rather than mounted from a mutable host path;
- `APP_ENV=benchmark`;
- PostgreSQL as the active database driver;
- a database name visibly containing `bench`, `perf`, `load`, `staging`, or `test`;
- Redis available for default, cache, queue, and lock logical databases;
- environment-provided secrets only;
- no public database or Redis endpoint requirement.

The entrypoint rejects `DB_URL` and `REDIS_URL` deliberately. URL-style configuration can override explicit database fields and Redis logical DB selection. Use the individual private-network fields documented below.

`TASK0024_BENCHMARK_MIGRATE=1` may be used to apply normal forward Laravel migrations with `artisan migrate --force --no-interaction` to the dedicated benchmark database. `migrate:fresh`, destructive database reset, Redis `FLUSHDB`/`FLUSHALL`, and shared-infrastructure reset are prohibited.

## Railway deployment mapping

Railway is the selected hosted path once the Railway integration/account is connected and the applicable resource/cost state has been reviewed. PostgreSQL and Redis should remain private within the same Railway project/environment as the benchmark runner.

Railway's legacy `railway.json` / `railway.toml` Config-as-Code is deprecated in 2026 and new services cannot opt into it. Do not create or depend on `railway.benchmark.json`. Configure the benchmark service with the current Railway plugin/CLI/service settings instead.

For the benchmark runner service:

1. Connect `Vertex-Systems-Network/vsn-marketing` as the GitHub source.
2. Deploy the exact selected benchmark source commit, not a moving branch tip.
3. Set `RAILWAY_DOCKERFILE_PATH=/docker/benchmark/Dockerfile` (or select the same Dockerfile path in service settings).
4. Do not expose a public domain; the runner is an internal operator service.
5. Add dedicated PostgreSQL and Redis services to the same Railway project/environment and keep them private.
6. Prefer sealed variables for secrets.
7. Keep the container's default idle command. Do not configure deployment startup to execute a benchmark automatically.

Railway service-reference variables for the runner should map to the database services rather than public endpoints. Assuming the services are named `Postgres` and `Redis`, use the current Railway reference syntax:

```text
APP_ENV=benchmark
APP_DEBUG=false
APP_KEY=<sealed generated Laravel application key>
LOG_CHANNEL=stderr
LOG_LEVEL=info

DB_CONNECTION=pgsql
DB_HOST=${{Postgres.PGHOST}}
DB_PORT=${{Postgres.PGPORT}}
DB_DATABASE=vsn_marketing_benchmark
DB_USERNAME=${{Postgres.PGUSER}}
DB_PASSWORD=${{Postgres.PGPASSWORD}}
DB_SSLMODE=prefer

CACHE_STORE=redis
SESSION_DRIVER=redis
QUEUE_CONNECTION=redis
QUEUE_FAILED_DRIVER=database-uuids
REDIS_CLIENT=phpredis
REDIS_HOST=${{Redis.REDISHOST}}
REDIS_PORT=${{Redis.REDISPORT}}
REDIS_USERNAME=${{Redis.REDISUSER}}
REDIS_PASSWORD=${{Redis.REDISPASSWORD}}
REDIS_DB=0
REDIS_CACHE_DB=1
REDIS_QUEUE_DB=2
REDIS_LOCK_DB=3
REDIS_QUEUE_CONNECTION=queue
REDIS_QUEUE=default
REDIS_QUEUE_RETRY_AFTER=120
REDIS_QUEUE_BLOCK_FOR=5

TASK0024_BENCHMARK_SOURCE_SHA=<exact selected benchmark source SHA>
TASK0024_BENCHMARK_MIGRATE=1
```

Do **not** set `DB_URL` or `REDIS_URL` on the benchmark runner. If the Railway PostgreSQL template initially exposes a different database name, create/use the dedicated `vsn_marketing_benchmark` database before capture and make the explicit `DB_DATABASE` value match the actual active database. The capture tool independently verifies the active database name.

## Preflight

Before collecting evidence, enter the deployed runner using the connected provider shell/SSH facility and verify:

```bash
cd /workspace
php -v
php -m | grep -E 'pcntl|pdo_pgsql|redis'
SOURCE_SHA="$RAILWAY_GIT_COMMIT_SHA"
printf '%s' "$SOURCE_SHA" | grep -Eq '^[0-9a-fA-F]{40}$'
test "$SOURCE_SHA" = "$TASK0024_BENCHMARK_SOURCE_SHA"
printf '%s\n' "$SOURCE_SHA"
printf '%s\n' "$TASK0024_BENCHMARK_SOURCE_SHA"
php tools/task0024_benchmark_capture.php --help
```

The two hosted source SHA values must be identical and full 40-character SHAs. Do not require or synthesize `.git` metadata inside the Railway image. The environment must be dedicated to this benchmark. Confirm PostgreSQL and Redis are healthy and no unrelated workload shares the resources during the measured windows.

If migrations are enabled, confirm the dedicated database is migrated before measurement. Warmup is performed by the capture tool and remains separate from measured runs.

## Capture delivery evidence

Use a unique benchmark ID and output path. The following parameters are the canonical initial measurement shape; changing them requires recording the changed assumptions in the evidence rather than silently comparing unlike runs.

```bash
cd /workspace
SOURCE_SHA="$RAILWAY_GIT_COMMIT_SHA"
printf '%s' "$SOURCE_SHA" | grep -Eq '^[0-9a-fA-F]{40}$'
test "$SOURCE_SHA" = "$TASK0024_BENCHMARK_SOURCE_SHA"

php tools/task0024_benchmark_capture.php \
  --scenario=delivery \
  --benchmark-id=task0024-delivery-prodrep-01 \
  --commit-sha="$SOURCE_SHA" \
  --database="$DB_DATABASE" \
  --runs=2 \
  --operations=200 \
  --concurrency=8 \
  --seed=24 \
  --warmup-seconds=5 \
  --measurement-window-seconds=30 \
  --output=/workspace/task0024-delivery-evidence.json \
  --ack=I_ACKNOWLEDGE_DEDICATED_NON_PRODUCTION_BENCHMARK_ENVIRONMENT
```

The delivery evidence must contain raw `queue_age_ms`, raw `end_to_end_ms`, and sustained throughput observations.

## Capture reconciliation evidence

```bash
cd /workspace
SOURCE_SHA="$RAILWAY_GIT_COMMIT_SHA"
printf '%s' "$SOURCE_SHA" | grep -Eq '^[0-9a-fA-F]{40}$'
test "$SOURCE_SHA" = "$TASK0024_BENCHMARK_SOURCE_SHA"

php tools/task0024_benchmark_capture.php \
  --scenario=reconciliation \
  --benchmark-id=task0024-reconciliation-prodrep-01 \
  --commit-sha="$SOURCE_SHA" \
  --database="$DB_DATABASE" \
  --runs=2 \
  --operations=200 \
  --concurrency=8 \
  --seed=24 \
  --warmup-seconds=5 \
  --measurement-window-seconds=30 \
  --output=/workspace/task0024-reconciliation-evidence.json \
  --ack=I_ACKNOWLEDGE_DEDICATED_NON_PRODUCTION_BENCHMARK_ENVIRONMENT
```

The reconciliation evidence must contain raw `reconciliation_lag_ms` and applicable sustained throughput observations.

## Evidence validation and human approval

Each capture is validated by `tools/delivery_benchmark_evidence.py` before the capture tool atomically publishes the output. Preserve the resulting exact evidence documents and fingerprints.

Only after both real evidence documents exist may the measured p95/p99 and throughput results be presented to the human Delivery owner. The owner must explicitly approve the numeric threshold set tied to:

- the exact benchmark source SHA;
- the exact delivery evidence fingerprint;
- the exact reconciliation evidence fingerprint;
- a real `approved_by` identity;
- a real `approved_at` timestamp.

The user's authorization to provision/run this environment is not a substitute for that later numeric review. No AI agent may infer, round up, auto-approve, or invent the threshold values.

After human numeric approval, commit the evidence and approved manifest on a descendant final-certification branch, replace canonical `TBD_MEASURED` values only from that manifest, then run `tools/task0024_certification_gate.py` and every required exact-head CI/release/continuity gate.

## Prohibited shortcuts

- Do not use GitHub-hosted CI wall-clock duration as SLO evidence.
- Do not run against production or a shared customer database.
- Do not expose PostgreSQL or Redis publicly merely to make capture easier.
- Do not fabricate Git metadata or source SHA evidence.
- Do not auto-run the benchmark during deployment.
- Do not infer thresholds from the benchmark automatically.
- Do not claim external provider/network latency from this internal benchmark.
- Do not start TASK-0025 / PHASE-05 until TASK-0024 is fully certified.
