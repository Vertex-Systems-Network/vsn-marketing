<?php

use Symfony\Component\Process\Process;

function task0051CaptureTestCommand(string $output): array
{
    return [
        PHP_BINARY, dirname(__DIR__, 3).'/tools/task0051_benchmark_capture.php',
        '--benchmark-id=journey-safety-test', '--commit-sha='.str_repeat('a', 40),
        '--database=vsn_marketing_benchmark', '--resource-profile=ci-safety-test',
        '--runner-image-sha='.str_repeat('b', 64), '--cpu-count=2', '--memory-mib=1024',
        '--runs=2', '--operations=20', '--concurrency=2', '--seed=51',
        '--output='.$output, '--ack=I_ACKNOWLEDGE_DEDICATED_NON_PRODUCTION_BENCHMARK_ENVIRONMENT',
    ];
}

it('explains the external capture scope without starting a workload', function (): void {
    $root = dirname(__DIR__, 3);
    $process = new Process([PHP_BINARY, $root.'/tools/task0051_benchmark_capture.php', '--help'], $root);
    $process->mustRun();
    expect($process->getOutput())->toContain('external authorized benchmark only')
        ->toContain('No provider action')
        ->toContain('task0051_benchmark_capture.php');
});

it('rejects missing acknowledgement and non-benchmark environment before bootstrapping', function (): void {
    $root = dirname(__DIR__, 3);
    $command = task0051CaptureTestCommand(sys_get_temp_dir().'/journey-safety.json');
    $missingAck = new Process(array_slice($command, 0, -1), $root, ['APP_ENV' => 'benchmark']);
    $missingAck->run();
    expect($missingAck->getExitCode())->toBe(2)
        ->and($missingAck->getErrorOutput())->toContain('exact acknowledgement');

    $production = new Process($command, $root, ['APP_ENV' => 'production']);
    $production->run();
    expect($production->getExitCode())->toBe(2)
        ->and($production->getErrorOutput())->toContain('APP_ENV=benchmark');
});

it('rejects existing output and unsafe workload budgets before bootstrap', function (): void {
    $root = dirname(__DIR__, 3);
    $existing = tempnam(sys_get_temp_dir(), 'journey-evidence-');
    expect($existing)->toBeString();
    try {
        $collision = new Process(task0051CaptureTestCommand($existing), $root, ['APP_ENV' => 'benchmark']);
        $collision->run();
        expect($collision->getExitCode())->toBe(2)
            ->and($collision->getErrorOutput())->toContain('output must be a new file');

        $budget = new Process(array_map(
            static fn (string $arg): string => $arg === '--operations=20' ? '--operations=1000000' : $arg,
            task0051CaptureTestCommand(sys_get_temp_dir().'/journey-invalid-budget-'.bin2hex(random_bytes(4)).'.json'),
        ), $root, ['APP_ENV' => 'benchmark']);
        $budget->run();
        expect($budget->getExitCode())->toBe(2)
            ->and($budget->getErrorOutput())->toContain('--operations must be an integer');
    } finally {
        unlink($existing);
    }
});

it('rejects direct worker invocation without a bounded authorized payload', function (): void {
    $root = dirname(__DIR__, 3);
    $process = new Process([PHP_BINARY, $root.'/tools/task0051_benchmark_capture.php', '--worker'], $root, [
        'APP_ENV' => 'benchmark', 'TASK0051_CAPTURE_WORKER_PAYLOAD' => '',
    ]);
    $process->run();
    expect($process->getExitCode())->toBe(2)
        ->and($process->getErrorOutput())->toContain('worker payload missing or oversized');
});
