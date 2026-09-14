#!/bin/sh
set -eu

fail() {
    printf 'TASK-0024 benchmark runtime refused to start: %s\n' "$1" >&2
    exit 78
}

[ "${APP_ENV:-}" = "benchmark" ] || fail 'APP_ENV must be exactly benchmark'
[ "${DB_CONNECTION:-}" = "pgsql" ] || fail 'DB_CONNECTION must be exactly pgsql'
[ -n "${APP_KEY:-}" ] || fail 'APP_KEY must be provided by the environment'
[ -n "${DB_HOST:-}" ] || fail 'DB_HOST must be provided by the environment'
[ -n "${DB_PORT:-}" ] || fail 'DB_PORT must be provided by the environment'
[ -n "${DB_DATABASE:-}" ] || fail 'DB_DATABASE must be provided by the environment'
[ -n "${DB_USERNAME:-}" ] || fail 'DB_USERNAME must be provided by the environment'
[ -n "${REDIS_HOST:-}" ] || fail 'REDIS_HOST must be provided by the environment'
[ -n "${REDIS_PORT:-}" ] || fail 'REDIS_PORT must be provided by the environment'

# URL-style variables can silently override the per-field PostgreSQL/Redis
# settings and Redis logical DB separation. The benchmark contract uses the
# explicit private-network fields instead.
[ -z "${DB_URL:-}" ] || fail 'DB_URL must be unset; use explicit PostgreSQL fields'
[ -z "${REDIS_URL:-}" ] || fail 'REDIS_URL must be unset; use explicit Redis fields'

database_name=$(printf '%s' "$DB_DATABASE" | tr '[:upper:]' '[:lower:]')
case "$database_name" in
    *bench*|*perf*|*load*|*staging*|*test*) ;;
    *) fail 'DB_DATABASE must visibly identify a benchmark/perf/load/staging/test database' ;;
esac

# /workspace is the immutable hosted-runtime path. A path override exists only
# so repository tests/local diagnostics can execute this preflight outside the
# container. Railway-hosted execution is not allowed to override it.
workspace=${TASK0024_BENCHMARK_WORKSPACE:-/workspace}
case "$workspace" in
    /*) ;;
    *) fail 'TASK0024_BENCHMARK_WORKSPACE must be an absolute path' ;;
esac

railway_environment_id=${RAILWAY_ENVIRONMENT_ID:-}
source_sha=${TASK0024_BENCHMARK_SOURCE_SHA:-}
railway_sha=${RAILWAY_GIT_COMMIT_SHA:-}

[ -n "$source_sha" ] || fail 'TASK0024_BENCHMARK_SOURCE_SHA is required'
printf '%s' "$source_sha" | grep -Eq '^[0-9a-fA-F]{40}$' \
    || fail 'TASK0024_BENCHMARK_SOURCE_SHA must be a 40-character commit SHA'

if [ -n "$railway_environment_id" ]; then
    [ "$workspace" = "/workspace" ] \
        || fail 'Railway benchmark runtime must use immutable /workspace source path'
    [ -n "$railway_sha" ] \
        || fail 'Railway benchmark runtime requires RAILWAY_GIT_COMMIT_SHA source attestation'
    printf '%s' "$railway_sha" | grep -Eq '^[0-9a-fA-F]{40}$' \
        || fail 'RAILWAY_GIT_COMMIT_SHA must be a 40-character commit SHA'
    [ "$source_sha" = "$railway_sha" ] \
        || fail 'explicit benchmark source SHA does not match Railway deployment SHA'
else
    # Local/non-Railway execution has a real Git checkout, so attest against
    # checkout HEAD. Hosted Railway source archives intentionally omit .git;
    # there the platform's immutable deployment SHA is the authoritative source.
    command -v git >/dev/null 2>&1 || fail 'git is required for local source attestation'
    git -C "$workspace" rev-parse --is-inside-work-tree >/dev/null 2>&1 \
        || fail 'Git metadata is required outside Railway-hosted execution'
    actual_sha=$(git -C "$workspace" rev-parse HEAD 2>/dev/null) \
        || fail 'unable to resolve checkout HEAD'
    [ "$actual_sha" = "$source_sha" ] \
        || fail 'checkout HEAD does not match TASK0024_BENCHMARK_SOURCE_SHA'

    if [ -n "$railway_sha" ]; then
        printf '%s' "$railway_sha" | grep -Eq '^[0-9a-fA-F]{40}$' \
            || fail 'RAILWAY_GIT_COMMIT_SHA must be a 40-character commit SHA'
        [ "$actual_sha" = "$railway_sha" ] \
            || fail 'checkout HEAD does not match RAILWAY_GIT_COMMIT_SHA'
    fi
fi

[ -f "$workspace/artisan" ] || fail "application source is missing from $workspace"
[ -f "$workspace/vendor/autoload.php" ] || fail 'locked Composer dependencies are missing'

case "${TASK0024_BENCHMARK_MIGRATE:-0}" in
    0) ;;
    1) php "$workspace/artisan" migrate --force --no-interaction ;;
    *) fail 'TASK0024_BENCHMARK_MIGRATE must be 0 or 1' ;;
esac

exec "$@"
