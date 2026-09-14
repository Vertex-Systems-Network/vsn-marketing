<?php

use Symfony\Component\Process\Process;

function task0024BenchmarkEnvironmentRoot(): string
{
    return dirname(__DIR__, 3);
}

function task0024BenchmarkEnvironmentBaseEnv(): array
{
    return [
        'APP_ENV' => 'benchmark',
        'APP_KEY' => 'base64:test-only-key',
        'DB_CONNECTION' => 'pgsql',
        'DB_HOST' => 'postgres.internal',
        'DB_PORT' => '5432',
        'DB_DATABASE' => 'vsn_marketing_benchmark',
        'DB_USERNAME' => 'vsn',
        'REDIS_HOST' => 'redis.internal',
        'REDIS_PORT' => '6379',
        'TASK0024_BENCHMARK_MIGRATE' => '0',
    ];
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
        ->not->toContain('task0024_benchmark_capture.php')
        ->toContain('TASK0024_BENCHMARK_WORKSPACE:-/workspace')
        ->toContain('Railway benchmark runtime must use immutable /workspace source path');
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
    ], $root, array_merge(task0024BenchmarkEnvironmentBaseEnv(), [
        'DB_DATABASE' => 'vsn_marketing',
        'TASK0024_BENCHMARK_SOURCE_SHA' => str_repeat('a', 40),
    ]));
    $process->setTimeout(10);
    $process->run();

    expect($process->getExitCode())->toBe(78)
        ->and($process->getErrorOutput())
        ->toContain('DB_DATABASE must visibly identify a benchmark/perf/load/staging/test database');
});

it('accepts a safe local idle runtime only when the exact repository head is attested', function (): void {
    $root = task0024BenchmarkEnvironmentRoot();
    $git = new Process(['git', 'rev-parse', 'HEAD'], $root);
    $git->setTimeout(10);
    $git->mustRun();
    $head = trim($git->getOutput());

    $process = new Process([
        'sh',
        $root.'/docker/benchmark/entrypoint.sh',
        'true',
    ], $root, array_merge(task0024BenchmarkEnvironmentBaseEnv(), [
        'TASK0024_BENCHMARK_SOURCE_SHA' => $head,
        'TASK0024_BENCHMARK_WORKSPACE' => $root,
    ]));
    $process->setTimeout(10);
    $process->mustRun();

    expect($process->getExitCode())->toBe(0);
});

it('rejects workspace overrides on Railway-hosted benchmark execution', function (): void {
    $root = task0024BenchmarkEnvironmentRoot();
    $git = new Process(['git', 'rev-parse', 'HEAD'], $root);
    $git->setTimeout(10);
    $git->mustRun();
    $head = trim($git->getOutput());

    $process = new Process([
        'sh',
        $root.'/docker/benchmark/entrypoint.sh',
        'true',
    ], $root, array_merge(task0024BenchmarkEnvironmentBaseEnv(), [
        'TASK0024_BENCHMARK_SOURCE_SHA' => $head,
        'TASK0024_BENCHMARK_WORKSPACE' => $root,
        'RAILWAY_ENVIRONMENT_ID' => 'test-environment-id',
        'RAILWAY_GIT_COMMIT_SHA' => $head,
    ]));
    $process->setTimeout(10);
    $process->run();

    expect($process->getExitCode())->toBe(78)
        ->and($process->getErrorOutput())
        ->toContain('Railway benchmark runtime must use immutable /workspace source path');
});

it('requires Railway immutable commit metadata for hosted source attestation', function (): void {
    $root = task0024BenchmarkEnvironmentRoot();
    $source = str_repeat('a', 40);

    $process = new Process([
        'sh',
        $root.'/docker/benchmark/entrypoint.sh',
        'true',
    ], $root, array_merge(task0024BenchmarkEnvironmentBaseEnv(), [
        'TASK0024_BENCHMARK_SOURCE_SHA' => $source,
        'RAILWAY_ENVIRONMENT_ID' => 'test-environment-id',
    ]));
    $process->setTimeout(10);
    $process->run();

    expect($process->getExitCode())->toBe(78)
        ->and($process->getErrorOutput())
        ->toContain('Railway benchmark runtime requires RAILWAY_GIT_COMMIT_SHA source attestation');
});

it('rejects a Railway deployment SHA that differs from the explicit benchmark source', function (): void {
    $root = task0024BenchmarkEnvironmentRoot();

    $process = new Process([
        'sh',
        $root.'/docker/benchmark/entrypoint.sh',
        'true',
    ], $root, array_merge(task0024BenchmarkEnvironmentBaseEnv(), [
        'TASK0024_BENCHMARK_SOURCE_SHA' => str_repeat('a', 40),
        'RAILWAY_ENVIRONMENT_ID' => 'test-environment-id',
        'RAILWAY_GIT_COMMIT_SHA' => str_repeat('b', 40),
    ]));
    $process->setTimeout(10);
    $process->run();

    expect($process->getExitCode())->toBe(78)
        ->and($process->getErrorOutput())
        ->toContain('explicit benchmark source SHA does not match Railway deployment SHA');
});

it('uses Railway commit metadata instead of requiring embedded git metadata on hosted execution', function (): void {
    $root = task0024BenchmarkEnvironmentRoot();
    $entrypoint = file_get_contents($root.'/docker/benchmark/entrypoint.sh');

    expect($entrypoint)->toBeString()
        ->toContain('if [ -n "$railway_environment_id" ]; then')
        ->toContain('Railway benchmark runtime requires RAILWAY_GIT_COMMIT_SHA source attestation')
        ->toContain('[ "$source_sha" = "$railway_sha" ]')
        ->toContain('Git metadata is required outside Railway-hosted execution')
        ->not->toContain('Git metadata is required; do not run an unattested benchmark image');
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
