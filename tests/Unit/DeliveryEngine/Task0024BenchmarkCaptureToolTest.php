<?php

use Symfony\Component\Process\Process;

it('exposes the benchmark safety contract without booting the application', function (): void {
    $process = new Process([
        PHP_BINARY,
        base_path('tools/task0024_benchmark_capture.php'),
        '--help',
    ], base_path());
    $process->setTimeout(10);
    $process->mustRun();

    expect($process->getOutput())
        ->toContain('APP_ENV must be exported as exactly: benchmark')
        ->toContain('I_ACKNOWLEDGE_DEDICATED_NON_PRODUCTION_BENCHMARK_ENVIRONMENT')
        ->toContain('does not infer thresholds')
        ->toContain('does not measure external provider/network latency')
        ->toContain('--seed=24');
});

it('fails closed before application bootstrap without the exact acknowledgement', function (): void {
    $process = new Process([
        PHP_BINARY,
        base_path('tools/task0024_benchmark_capture.php'),
        '--scenario=delivery',
        '--benchmark-id=task0024-test',
        '--commit-sha='.str_repeat('a', 40),
        '--database=vsn_marketing_benchmark',
        '--output='.sys_get_temp_dir().'/task0024-test.json',
    ], base_path(), ['APP_ENV' => 'benchmark']);
    $process->setTimeout(10);
    $process->run();

    expect($process->getExitCode())->toBe(2)
        ->and($process->getErrorOutput())->toContain('exact --ack acknowledgement is required');
});

it('fails closed before application bootstrap outside the benchmark environment', function (): void {
    $process = new Process([
        PHP_BINARY,
        base_path('tools/task0024_benchmark_capture.php'),
        '--scenario=delivery',
        '--benchmark-id=task0024-test',
        '--commit-sha='.str_repeat('a', 40),
        '--database=vsn_marketing_benchmark',
        '--output='.sys_get_temp_dir().'/task0024-test.json',
        '--ack=I_ACKNOWLEDGE_DEDICATED_NON_PRODUCTION_BENCHMARK_ENVIRONMENT',
    ], base_path(), ['APP_ENV' => 'testing']);
    $process->setTimeout(10);
    $process->run();

    expect($process->getExitCode())->toBe(2)
        ->and($process->getErrorOutput())->toContain('APP_ENV must be exported as exactly benchmark');
});

it('contains no destructive shared infrastructure reset primitive', function (): void {
    $source = file_get_contents(base_path('tools/task0024_benchmark_capture.php'));

    expect($source)->toBeString()
        ->not->toContain("Artisan::call('migrate:fresh'")
        ->not->toContain('flushdb')
        ->not->toContain('FLUSHDB');
});
