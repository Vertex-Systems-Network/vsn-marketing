<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Redis\RedisManager;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

const TASK0051_ACK = 'I_ACKNOWLEDGE_DEDICATED_NON_PRODUCTION_BENCHMARK_ENVIRONMENT';

function task0051Block(string $reason): never
{
    fwrite(STDERR, 'TASK-0051 benchmark preflight blocked: '.$reason.PHP_EOL);
    exit(2);
}

function task0051Help(): string
{
    return <<<'HELP'
TASK-0051 journey benchmark preflight (read-only; does not run a benchmark)

php tools/task0051_benchmark_preflight.php \
  --commit-sha=<exact-40-character-sha> \
  --database=<dedicated-benchmark-database> \
  --ack=I_ACKNOWLEDGE_DEDICATED_NON_PRODUCTION_BENCHMARK_ENVIRONMENT

APP_ENV must be exported as benchmark. On Railway, TASK0051_BENCHMARK_SOURCE_SHA
and RAILWAY_GIT_COMMIT_SHA must both match --commit-sha. On a local checkout,
the real Git HEAD must match --commit-sha. DB_URL and REDIS_URL are rejected.
This reports only non-secret environment and configuration identity. It creates
no fixtures, executes no workload, and is not TASK-0051 AC-5 evidence.
HELP;
}

function task0051VerifySource(string $expected): void
{
    if (trim((string) getenv('RAILWAY_ENVIRONMENT_ID')) !== '') {
        foreach (['TASK0051_BENCHMARK_SOURCE_SHA', 'RAILWAY_GIT_COMMIT_SHA'] as $name) {
            $value = strtolower(trim((string) getenv($name)));
            if (! preg_match('/^[0-9a-f]{40}$/D', $value)) {
                task0051Block($name.' must be a full immutable source SHA on Railway');
            }
            if ($value !== $expected) {
                task0051Block($name.' does not match --commit-sha');
            }
        }

        return;
    }

    $process = new Process(['git', 'rev-parse', '--verify', 'HEAD'], dirname(__DIR__));
    $process->setTimeout(10);
    $process->run();
    if (! $process->isSuccessful()) {
        task0051Block('unable to resolve real checkout HEAD');
    }
    if (strtolower(trim($process->getOutput())) !== $expected) {
        task0051Block('checkout HEAD does not match --commit-sha');
    }
}

try {
    $options = getopt('', ['help', 'commit-sha:', 'database:', 'ack:']);
    if (isset($options['help'])) {
        echo task0051Help().PHP_EOL;
        exit(0);
    }
    if (($options['ack'] ?? null) !== TASK0051_ACK) {
        task0051Block('the exact --ack acknowledgement is required before application bootstrap');
    }
    if ((string) getenv('APP_ENV') !== 'benchmark') {
        task0051Block('APP_ENV must be exported as exactly benchmark');
    }
    if (trim((string) getenv('DB_URL')) !== '' || trim((string) getenv('REDIS_URL')) !== '') {
        task0051Block('DB_URL and REDIS_URL may override explicit isolated connections');
    }
    $sha = strtolower(trim((string) ($options['commit-sha'] ?? '')));
    if (! preg_match('/^[0-9a-f]{40}$/D', $sha)) {
        task0051Block('--commit-sha must be a full 40-character SHA');
    }
    $database = trim((string) ($options['database'] ?? ''));
    if ($database === '' || preg_match('/(bench|perf|load|staging|test)/i', $database) !== 1) {
        task0051Block('--database must visibly identify a dedicated benchmark/test database');
    }
    $root = dirname(__DIR__);
    if (! is_file($root.'/vendor/autoload.php') || ! is_file($root.'/bootstrap/app.php')) {
        task0051Block('run from a complete repository checkout with installed Composer dependencies');
    }
    require_once $root.'/vendor/autoload.php';
    task0051VerifySource($sha);
    $app = require $root.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    if (! $app->environment('benchmark') || DB::connection()->getDriverName() !== 'pgsql') {
        task0051Block('booted environment must be benchmark with PostgreSQL');
    }
    if ((string) DB::connection()->getDatabaseName() !== $database) {
        task0051Block('--database does not match active PostgreSQL database');
    }
    foreach (['journeys', 'journey_versions', 'journey_executions', 'journey_enrollments', 'journey_node_attempts', 'journey_execution_transitions'] as $table) {
        if (! DB::getSchemaBuilder()->hasTable($table)) {
            task0051Block('required journey migration is missing: '.$table);
        }
    }
    $cap = config('journeys.max_active_enrollments_per_workspace');
    if (! is_numeric($cap) || (int) $cap < 1 || (string) (int) $cap !== (string) $cap) {
        task0051Block('journey workspace enrollment cap must be a configured positive integer');
    }
    $pong = app(RedisManager::class)->connection('locks')->command('ping');
    if ($pong === null || $pong === false) {
        task0051Block('Redis locks connection did not answer PING');
    }
    echo json_encode([
        'preflight' => 'passed',
        'benchmark_evidence' => false,
        'source_sha' => $sha,
        'database' => $database,
        'php_version' => PHP_VERSION,
        'postgresql_version' => DB::selectOne('SHOW server_version')->server_version,
        'enrollment_cap' => (int) $cap,
        'checked_at_utc' => gmdate(DATE_ATOM),
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).PHP_EOL;
} catch (Throwable $throwable) {
    task0051Block('unexpected '.get_debug_type($throwable).' at '.basename($throwable->getFile()).':'.$throwable->getLine());
}
