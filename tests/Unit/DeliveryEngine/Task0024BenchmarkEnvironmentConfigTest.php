<?php

use Symfony\Component\Process\Process;

function task0024BenchmarkEnvironmentRoot(): string
{
    return dirname(__DIR__, 3);
}

it('builds an immutable source-containing benchmark runtime with required extensions', function (): void {
    $root = task0024BenchmarkEnvironmentRoot();
    $dockerfile = file_get_contents($root.'/docker/benchmark/Dockerfile');

    expect($dockerfile)->toBeString()
        ->toContain('FROM php:8.5-cli-bookworm')
        ->toContain('bcmath mbstring pcntl pdo_pgsql zip')
        ->toContain('pecl install redis')
        ->toContain('COPY . /workspace')
        ->toContain('composer install')
        ->toContain('--no-dev')
        ->toContain('ENTRYPOINT ["/workspace/docker/benchmark/entrypoint.sh"]')
        ->toContain('CMD ["sleep", "infinity"]');
});

it('keeps deployment idle and benchmark execution operator-triggered', function (): void {
    $root = task0024BenchmarkEnvironmentRoot();
    $dockerfile = file_get_contents($root.'/docker/benchmark/Dockerfile');
    $entrypoint = file_get_contents($root.'/docker/benchmark/entrypoint.sh');

    expect($dockerfile)->toBeString()
        ->not->toContain('task0024_benchmark_capture.php')
        ->and($entrypoint)->toBeString()
        ->not->toContain('task0024_benchmark_capture.php');
});

it('rejects destructive reset primitives from the benchmark runtime contract', function (): void {
    $root = task0024BenchmarkEnvironmentRoot();
    $contract = implode("\n", [
        (string) file_get_contents($root.'/docker/benchmark/Dockerfile'),
        (string) file_get_contents($root.'/docker/benchmark/entrypoint.sh'),
    ]);

    expect(strtolower($contract))
        ->not->toContain('migrate:fresh')
        ->not->toContain('flushdb')
        ->not->toContain('flushall');
});

it('has syntactically valid fail-closed shell entrypoint', function (): void {
    $root = task0024BenchmarkEnvironmentRoot();
    $process = new Process(['sh', '-n', $root.'/docker/benchmark/entrypoint.sh'], $root);
    $process->setTimeout(10);
    $process->mustRun();

    expect($process->getExitCode())->toBe(0);
});

it('refuses to start outside the benchmark environment', function (): void {
    $root = task0024BenchmarkEnvironmentRoot();
    $process = new Process([
        'sh',
        $root.'/docker/benchmark/entrypoint.sh',
        'true',
    ], $root, ['APP_ENV' => 'testing']);
    $process->setTimeout(10);
    $process->run();

    expect($process->getExitCode())->toBe(78)
        ->and($process->getErrorOutput())->toContain('APP_ENV must be exactly benchmark');
});

it('refuses an unsafe database name before any migration or benchmark can run', function (): void {
    $root = task0024BenchmarkEnvironmentRoot();
    $process = new Process([
        'sh',
        $root.'/docker/benchmark/entrypoint.sh',
        'true',
    ], $root, [
        'APP_ENV' => 'benchmark',
        'APP_KEY' => 'base64:test-only-key',
        'DB_CONNECTION' => 'pgsql',
        'DB_HOST' => 'postgres.internal',
        'DB_PORT' => '5432',
        'DB_DATABASE' => 'vsn_marketing',
        'DB_USERNAME' => 'vsn',
        'REDIS_HOST' => 'redis.internal',
        'REDIS_PORT' => '6379',
        'TASK0024_BENCHMARK_SOURCE_SHA' => str_repeat('a', 40),
    ]);
    $process->setTimeout(10);
    $process->run();

    expect($process->getExitCode())->toBe(78)
        ->and($process->getErrorOutput())
        ->toContain('DB_DATABASE must visibly identify a benchmark/perf/load/staging/test database');
});

it('accepts a safe idle runtime only when the exact repository head is attested', function (): void {
    $root = task0024BenchmarkEnvironmentRoot();
    $git = new Process(['git', 'rev-parse', 'HEAD'], $root);
    $git->setTimeout(10);
    $git->mustRun();
    $head = trim($git->getOutput());

    $process = new Process([
        'sh',
        $root.'/docker/benchmark/entrypoint.sh',
        'true',
    ], $root, [
        'APP_ENV' => 'benchmark',
        'APP_KEY' => 'base64:test-only-key',
        'DB_CONNECTION' => 'pgsql',
        'DB_HOST' => 'postgres.internal',
        'DB_PORT' => '5432',
        'DB_DATABASE' => 'vsn_marketing_benchmark',
        'DB_USERNAME' => 'vsn',
        'REDIS_HOST' => 'redis.internal',
        'REDIS_PORT' => '6379',
        'TASK0024_BENCHMARK_SOURCE_SHA' => $head,
        'TASK0024_BENCHMARK_MIGRATE' => '0',
    ]);
    $process->setTimeout(10);
    $process->mustRun();

    expect($process->getExitCode())->toBe(0);
});

it('documents current Railway private-service mapping without deprecated config as code', function (): void {
    $root = task0024BenchmarkEnvironmentRoot();
    $runbook = file_get_contents($root.'/docs/operations/TASK-0024-BENCHMARK-ENV.md');

    expect($runbook)->toBeString()
        ->toContain('RAILWAY_DOCKERFILE_PATH=/docker/benchmark/Dockerfile')
        ->toContain('${{Postgres.PGHOST}}')
        ->toContain('${{Redis.REDISHOST}}')
        ->toContain('RAILWAY_GIT_COMMIT_SHA')
        ->toContain('Do **not** set `DB_URL` or `REDIS_URL`')
        ->toContain('new services cannot opt into it')
        ->toContain('No AI agent may infer, round up, auto-approve, or invent the threshold values.');

    expect(file_exists($root.'/railway.benchmark.json'))->toBeFalse();
});
