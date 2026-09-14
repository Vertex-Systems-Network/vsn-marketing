<?php

use Symfony\Component\Process\Process;

function task0024BenchmarkRepoRoot(): string
{
    return dirname(__DIR__, 3);
}

/** @return list<string> */
function task0024BenchmarkCommand(string $tool, string $commitSha, string $output): array
{
    return [
        PHP_BINARY,
        $tool,
        '--scenario=delivery',
        '--benchmark-id=task0024-test',
        '--commit-sha='.$commitSha,
        '--database=vsn_marketing_benchmark',
        '--runs=2',
        '--operations=2',
        '--concurrency=1',
        '--seed=24',
        '--warmup-seconds=0',
        '--measurement-window-seconds=1',
        '--output='.$output,
        '--ack=I_ACKNOWLEDGE_DEDICATED_NON_PRODUCTION_BENCHMARK_ENVIRONMENT',
    ];
}

it('exposes the benchmark safety contract without booting the application', function (): void {
    $root = task0024BenchmarkRepoRoot();
    $process = new Process([
        PHP_BINARY,
        $root.'/tools/task0024_benchmark_capture.php',
        '--help',
    ], $root);
    $process->setTimeout(10);
    $process->mustRun();

    $help = preg_replace('/\s+/', ' ', $process->getOutput()) ?? '';

    expect($help)
        ->toContain('APP_ENV must be exported as exactly: benchmark')
        ->toContain('I_ACKNOWLEDGE_DEDICATED_NON_PRODUCTION_BENCHMARK_ENVIRONMENT')
        ->toContain('does not infer thresholds')
        ->toContain('does not measure external provider/network latency')
        ->toContain('TASK0024_BENCHMARK_SOURCE_SHA')
        ->toContain('RAILWAY_GIT_COMMIT_SHA')
        ->toContain('exactly equal')
        ->toContain('benchmark-only breaker budget')
        ->toContain('warmup plus every repeated run')
        ->toContain('production breaker default is unchanged')
        ->toContain('--seed=24');
});

it('fails closed before application bootstrap without the exact acknowledgement', function (): void {
    $root = task0024BenchmarkRepoRoot();
    $process = new Process([
        PHP_BINARY,
        $root.'/tools/task0024_benchmark_capture.php',
        '--scenario=delivery',
        '--benchmark-id=task0024-test',
        '--commit-sha='.str_repeat('a', 40),
        '--database=vsn_marketing_benchmark',
        '--output='.sys_get_temp_dir().'/task0024-test.json',
    ], $root, ['APP_ENV' => 'benchmark']);
    $process->setTimeout(10);
    $process->run();

    expect($process->getExitCode())->toBe(2)
        ->and($process->getErrorOutput())->toContain('exact --ack acknowledgement is required');
});

it('fails closed before application bootstrap outside the benchmark environment', function (): void {
    $root = task0024BenchmarkRepoRoot();
    $process = new Process([
        PHP_BINARY,
        $root.'/tools/task0024_benchmark_capture.php',
        '--scenario=delivery',
        '--benchmark-id=task0024-test',
        '--commit-sha='.str_repeat('a', 40),
        '--database=vsn_marketing_benchmark',
        '--output='.sys_get_temp_dir().'/task0024-test.json',
        '--ack=I_ACKNOWLEDGE_DEDICATED_NON_PRODUCTION_BENCHMARK_ENVIRONMENT',
    ], $root, ['APP_ENV' => 'testing']);
    $process->setTimeout(10);
    $process->run();

    expect($process->getExitCode())->toBe(2)
        ->and($process->getErrorOutput())->toContain('APP_ENV must be exported as exactly benchmark');
});

it('requires Railway immutable commit metadata before application bootstrap', function (): void {
    $root = task0024BenchmarkRepoRoot();
    $source = str_repeat('a', 40);
    $process = new Process(
        task0024BenchmarkCommand(
            $root.'/tools/task0024_benchmark_capture.php',
            $source,
            sys_get_temp_dir().'/task0024-railway-missing-sha.json',
        ),
        $root,
        [
            'APP_ENV' => 'benchmark',
            'RAILWAY_ENVIRONMENT_ID' => 'test-environment-id',
            'TASK0024_BENCHMARK_SOURCE_SHA' => $source,
        ],
    );
    $process->setTimeout(10);
    $process->run();

    expect($process->getExitCode())->toBe(2)
        ->and($process->getErrorOutput())
        ->toContain('RAILWAY_GIT_COMMIT_SHA is required on Railway');
});

it('rejects Railway deployment SHA mismatch before application bootstrap', function (): void {
    $root = task0024BenchmarkRepoRoot();
    $source = str_repeat('a', 40);
    $process = new Process(
        task0024BenchmarkCommand(
            $root.'/tools/task0024_benchmark_capture.php',
            $source,
            sys_get_temp_dir().'/task0024-railway-mismatch.json',
        ),
        $root,
        [
            'APP_ENV' => 'benchmark',
            'RAILWAY_ENVIRONMENT_ID' => 'test-environment-id',
            'TASK0024_BENCHMARK_SOURCE_SHA' => $source,
            'RAILWAY_GIT_COMMIT_SHA' => str_repeat('b', 40),
        ],
    );
    $process->setTimeout(10);
    $process->run();

    expect($process->getExitCode())->toBe(2)
        ->and($process->getErrorOutput())
        ->toContain('--commit-sha does not match RAILWAY_GIT_COMMIT_SHA on Railway');
});

it('rejects explicit Railway benchmark source mismatch before application bootstrap', function (): void {
    $root = task0024BenchmarkRepoRoot();
    $expected = str_repeat('a', 40);
    $source = str_repeat('b', 40);
    $process = new Process(
        task0024BenchmarkCommand(
            $root.'/tools/task0024_benchmark_capture.php',
            $expected,
            sys_get_temp_dir().'/task0024-railway-source-mismatch.json',
        ),
        $root,
        [
            'APP_ENV' => 'benchmark',
            'RAILWAY_ENVIRONMENT_ID' => 'test-environment-id',
            'TASK0024_BENCHMARK_SOURCE_SHA' => $source,
            'RAILWAY_GIT_COMMIT_SHA' => $source,
        ],
    );
    $process->setTimeout(10);
    $process->run();

    expect($process->getExitCode())->toBe(2)
        ->and($process->getErrorOutput())
        ->toContain('--commit-sha does not match TASK0024_BENCHMARK_SOURCE_SHA on Railway');
});

it('accepts matching Railway source attestations without embedded git metadata', function (): void {
    $root = task0024BenchmarkRepoRoot();
    $source = str_repeat('a', 40);
    $temporaryRoot = sys_get_temp_dir().'/task0024-hosted-no-git-'.bin2hex(random_bytes(8));
    $toolDirectory = $temporaryRoot.'/tools';
    expect(mkdir($toolDirectory, 0777, true))->toBeTrue();
    expect(copy($root.'/tools/task0024_benchmark_capture.php', $toolDirectory.'/task0024_benchmark_capture.php'))->toBeTrue();

    try {
        $process = new Process(
            task0024BenchmarkCommand(
                $toolDirectory.'/task0024_benchmark_capture.php',
                $source,
                $temporaryRoot.'/evidence.json',
            ),
            $temporaryRoot,
            [
                'APP_ENV' => 'benchmark',
                'RAILWAY_ENVIRONMENT_ID' => 'test-environment-id',
                'TASK0024_BENCHMARK_SOURCE_SHA' => $source,
                'RAILWAY_GIT_COMMIT_SHA' => $source,
            ],
        );
        $process->setTimeout(10);
        $process->run();

        expect($process->getExitCode())->toBe(2)
            ->and($process->getErrorOutput())
            ->toContain('run from a complete repository checkout with installed Composer dependencies')
            ->not->toContain('unable to resolve checkout HEAD');
    } finally {
        @unlink($toolDirectory.'/task0024_benchmark_capture.php');
        @rmdir($toolDirectory);
        @rmdir($temporaryRoot);
    }
});

it('keeps real git checkout attestation outside Railway', function (): void {
    $root = task0024BenchmarkRepoRoot();
    $git = new Process(['git', 'rev-parse', 'HEAD'], $root);
    $git->setTimeout(10);
    $git->mustRun();
    $head = strtolower(trim($git->getOutput()));
    $mismatch = $head === str_repeat('a', 40) ? str_repeat('b', 40) : str_repeat('a', 40);

    $process = new Process(
        task0024BenchmarkCommand(
            $root.'/tools/task0024_benchmark_capture.php',
            $mismatch,
            sys_get_temp_dir().'/task0024-local-mismatch.json',
        ),
        $root,
        ['APP_ENV' => 'benchmark'],
    );
    $process->setTimeout(10);
    $process->run();

    expect($process->getExitCode())->toBe(2)
        ->and($process->getErrorOutput())
        ->toContain('checkout HEAD does not match --commit-sha');
});

it('turns unexpected worker exceptions into deterministic non-zero capture failures', function (): void {
    $root = task0024BenchmarkRepoRoot();
    $process = new Process([
        PHP_BINARY,
        $root.'/tools/task0024_benchmark_capture.php',
    ], $root, [
        'APP_ENV' => 'benchmark',
        'TASK0024_BENCHMARK_WORKER_PAYLOAD' => base64_encode('{'),
    ]);
    $process->setTimeout(10);
    $process->run();

    expect($process->getExitCode())->toBe(2)
        ->and($process->getErrorOutput())
        ->toContain('TASK-0024 benchmark capture blocked: unexpected JsonException')
        ->not->toContain('{');
});

it('rejects reconciliation workers without the benchmark-only breaker budget before bootstrap', function (): void {
    $root = task0024BenchmarkRepoRoot();
    $payload = base64_encode(json_encode([
        'ack' => 'I_ACKNOWLEDGE_DEDICATED_NON_PRODUCTION_BENCHMARK_ENVIRONMENT',
        'scenario' => 'reconciliation',
        'database' => 'vsn_marketing_benchmark',
    ], JSON_THROW_ON_ERROR));
    $process = new Process([
        PHP_BINARY,
        $root.'/tools/task0024_benchmark_capture.php',
    ], $root, [
        'APP_ENV' => 'benchmark',
        'TASK0024_BENCHMARK_WORKER_PAYLOAD' => $payload,
    ]);
    $process->setTimeout(10);
    $process->run();

    expect($process->getExitCode())->toBe(2)
        ->and($process->getErrorOutput())
        ->toContain('reconciliation worker breaker failure threshold is invalid');
});

it('derives reconciliation breaker isolation across warmup and every repeated run without changing production defaults', function (): void {
    $root = task0024BenchmarkRepoRoot();
    $source = file_get_contents($root.'/tools/task0024_benchmark_capture.php');
    $deliveryConfig = file_get_contents($root.'/config/delivery.php');

    expect($source)->toBeString()
        ->toContain('function task0024ReconciliationBreakerFailureThreshold(')
        ->toContain('$phaseCount = $runs + ($warmupSeconds > 0 ? 1 : 0);')
        ->toContain('return ($operations * $phaseCount) + 1;')
        ->toContain("'reconciliation_breaker_failure_threshold' => \$options['reconciliation_breaker_failure_threshold']")
        ->toContain("if ((\$payload['scenario'] ?? null) === 'reconciliation')")
        ->toContain("\$workerConfig['delivery.recovery.breaker_failure_threshold'] = \$reconciliationBreakerFailureThreshold;")
        ->toContain("'benchmark_controls' => \$options['scenario'] === 'reconciliation'")
        ->not->toContain("getenv('DELIVERY_RECOVERY_BREAKER_FAILURE_THRESHOLD')");

    expect($deliveryConfig)->toBeString()
        ->toContain("'breaker_failure_threshold' => env('DELIVERY_RECOVERY_BREAKER_FAILURE_THRESHOLD', 3)");
});

it('uses an explicit worker result marker instead of decoding arbitrary worker stdout', function (): void {
    $root = task0024BenchmarkRepoRoot();
    $source = file_get_contents($root.'/tools/task0024_benchmark_capture.php');

    expect($source)->toBeString()
        ->toContain("const TASK0024_WORKER_RESULT_PREFIX = 'TASK0024_WORKER_RESULT:';")
        ->toContain('TASK0024_WORKER_RESULT_PREFIX.json_encode([')
        ->toContain('function task0024DecodeWorkerResult(string $output): array')
        ->toContain("task0024Fail('benchmark worker result marker is missing')")
        ->toContain("task0024Fail('benchmark worker returned malformed JSON result')")
        ->toContain('catch (Throwable $throwable)')
        ->toContain('task0024Fail(task0024ThrowableDiagnostic($throwable))')
        ->not->toContain('json_decode(trim($process->getOutput()), true, 512, JSON_THROW_ON_ERROR)');
});

it('contains no destructive shared infrastructure reset primitive', function (): void {
    $root = task0024BenchmarkRepoRoot();
    $source = file_get_contents($root.'/tools/task0024_benchmark_capture.php');

    expect($source)->toBeString()
        ->not->toContain("Artisan::call('migrate:fresh'")
        ->not->toContain('flushdb')
        ->not->toContain('FLUSHDB');
});
