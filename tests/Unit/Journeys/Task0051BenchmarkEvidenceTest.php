<?php

use Symfony\Component\Process\Process;

function task0051SyntheticEvidence(): array
{
    $checks = array_fill_keys([
        'saturated', 'staleFenced', 'retry', 'deadLetter', 'cancelled',
        'lateFenced', 'unknownReview', 'replayPinned', 'crossWorkspaceRejected',
    ], true);
    $checks['measurements_ms'] = ['scenario' => 10.0, 'unknown_outcome' => 1.0, 'replay' => 2.0, 'replay_duplicate' => 1.0];
    $attempts = [];
    $enrollments = [];
    for ($i = 0; $i < 20; $i++) {
        $node = ['condition', 'wait', 'action'][$i % 3];
        $attempts[] = [
            'index' => $i, 'workspace_slot' => $i % 2, 'node' => $node,
            'claim_ms' => 1.0, 'duplicate_ms' => 0.4, 'complete_ms' => 1.2,
            'end_to_end_ms' => 2.6, 'outcome' => 'succeeded',
        ];
        $enrollments[] = ['index' => $i, 'workspace_slot' => $i % 2, 'enroll_ms' => 1.1, 'duplicate_ms' => 0.5];
    }
    $run = [
        'graph_hash' => str_repeat('b', 64), 'node_mix' => ['condition' => 7, 'wait' => 7, 'action' => 6],
        'operations' => 20, 'concurrency' => 2, 'elapsed_ms' => 300, 'throughput_per_second' => 66.667,
        'percentile_method' => 'nearest_rank',
        'latency_percentiles_ms' => [
            'enroll' => ['p50' => 1.1, 'p95' => 1.1, 'p99' => 1.1],
            'claim' => ['p50' => 1.0, 'p95' => 1.0, 'p99' => 1.0],
            'complete' => ['p50' => 1.2, 'p95' => 1.2, 'p99' => 1.2],
            'end_to_end' => ['p50' => 2.6, 'p95' => 2.6, 'p99' => 2.6],
        ],
        'enrollment_samples' => $enrollments, 'attempt_samples' => $attempts, 'fault_checks' => $checks,
    ];

    return [
        'schema_version' => 1, 'benchmark_id' => 'journey-validator-test', 'source_sha' => str_repeat('a', 40),
        'resource_profile' => 'test', 'runner_image_sha' => str_repeat('d', 64), 'cpu_count' => 2,
        'memory_mib' => 1024, 'fixture_seed' => 51, 'database' => 'vsn_marketing_benchmark',
        'preflight' => ['preflight' => 'passed', 'source_sha' => str_repeat('a', 40), 'database' => 'vsn_marketing_benchmark'],
        'provider_latency_measured' => false, 'redis_queue_measured' => false,
        'warmup' => ['operations' => 20, 'elapsed_ms' => 300, 'fault_checks' => $checks],
        'runs' => [$run, $run],
    ];
}

function task0051ValidateEvidence(array $document): Process
{
    $root = dirname(__DIR__, 3);
    $process = new Process(
        ['python3', $root.'/tools/task0051_benchmark_evidence.py', '--stdin'],
        $root,
        null,
        json_encode($document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
    );
    $process->run();

    return $process;
}

it('validates consistent synthetic capture shape without declaring it a real benchmark', function (): void {
    $process = task0051ValidateEvidence(task0051SyntheticEvidence());
    expect($process->getExitCode())->toBe(0)
        ->and($process->getOutput())->toContain('evidence structure validated');
});

it('rejects sample loss, source drift, and false external-latency claims', function (): void {
    $missing = task0051SyntheticEvidence();
    array_pop($missing['runs'][0]['attempt_samples']);
    expect(task0051ValidateEvidence($missing)->getExitCode())->toBe(2);

    $drift = task0051SyntheticEvidence();
    $drift['preflight']['source_sha'] = str_repeat('c', 40);
    expect(task0051ValidateEvidence($drift)->getExitCode())->toBe(2);

    $claim = task0051SyntheticEvidence();
    $claim['provider_latency_measured'] = true;
    expect(task0051ValidateEvidence($claim)->getExitCode())->toBe(2);

    $sensitive = task0051SyntheticEvidence();
    $sensitive['runs'][0]['attempt_samples'][0]['email'] = 'private@example.test';
    expect(task0051ValidateEvidence($sensitive)->getExitCode())->toBe(2);
});
