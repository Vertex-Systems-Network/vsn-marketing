<?php

use Symfony\Component\Process\Process;

function task0051PreflightCommand(string $sha): array
{
    return [
        PHP_BINARY,
        dirname(__DIR__, 3).'/tools/task0051_benchmark_preflight.php',
        '--commit-sha='.$sha,
        '--database=vsn_marketing_benchmark',
        '--ack=I_ACKNOWLEDGE_DEDICATED_NON_PRODUCTION_BENCHMARK_ENVIRONMENT',
    ];
}

it('documents read-only semantics without bootstrapping the application', function (): void {
    $root = dirname(__DIR__, 3);
    $process = new Process([PHP_BINARY, $root.'/tools/task0051_benchmark_preflight.php', '--help'], $root);
    $process->mustRun();

    expect($process->getOutput())->toContain('does not run a benchmark')
        ->toContain('not TASK-0051 AC-5 evidence')
        ->toContain('TASK0051_BENCHMARK_SOURCE_SHA');
});

it('rejects missing acknowledgement and production environment before bootstrap', function (): void {
    $root = dirname(__DIR__, 3);
    $withoutAck = new Process(array_slice(task0051PreflightCommand(str_repeat('a', 40)), 0, -1), $root, ['APP_ENV' => 'benchmark']);
    $withoutAck->run();
    expect($withoutAck->getExitCode())->toBe(2)
        ->and($withoutAck->getErrorOutput())->toContain('exact --ack acknowledgement');

    $production = new Process(task0051PreflightCommand(str_repeat('a', 40)), $root, ['APP_ENV' => 'production']);
    $production->run();
    expect($production->getExitCode())->toBe(2)
        ->and($production->getErrorOutput())->toContain('APP_ENV must be exported');
});

it('rejects ambiguous URL-based connections before source or database access', function (): void {
    $root = dirname(__DIR__, 3);
    $process = new Process(task0051PreflightCommand(str_repeat('a', 40)), $root, ['APP_ENV' => 'benchmark', 'DB_URL' => 'postgres://example.invalid/production']);
    $process->run();
    expect($process->getExitCode())->toBe(2)
        ->and($process->getErrorOutput())->toContain('DB_URL and REDIS_URL');
});

it('requires immutable matching hosted source metadata before application bootstrap', function (): void {
    $root = dirname(__DIR__, 3);
    $sha = str_repeat('a', 40);
    $process = new Process(task0051PreflightCommand($sha), $root, [
        'APP_ENV' => 'benchmark',
        'RAILWAY_ENVIRONMENT_ID' => 'test',
        'TASK0051_BENCHMARK_SOURCE_SHA' => $sha,
        'RAILWAY_GIT_COMMIT_SHA' => str_repeat('b', 40),
    ]);
    $process->run();
    expect($process->getExitCode())->toBe(2)
        ->and($process->getErrorOutput())->toContain('RAILWAY_GIT_COMMIT_SHA does not match --commit-sha');
});
