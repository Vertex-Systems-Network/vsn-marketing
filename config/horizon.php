<?php

use Illuminate\Support\Str;

return [
    'domain' => env('HORIZON_DOMAIN'),
    'path' => env('HORIZON_PATH', 'horizon'),
    'use' => env('HORIZON_REDIS_CONNECTION', 'default'),
    'prefix' => env('HORIZON_PREFIX', Str::slug((string) env('APP_NAME', 'vsn-marketing'), '_').'_horizon:'),
    'middleware' => ['web'],
    'waits' => ['redis:default' => 60, 'redis:outbox' => 60],
    'trim' => ['recent' => 60, 'pending' => 60, 'completed' => 60, 'recent_failed' => 10080, 'failed' => 10080, 'monitored' => 10080],
    'silenced' => [],
    'metrics' => ['trim_snapshots' => ['job' => 24, 'queue' => 24]],
    'fast_termination' => false,
    'memory_limit' => 64,
    'defaults' => [
        'supervisor-core' => [
            'connection' => 'redis', 'queue' => ['default', 'outbox'], 'balance' => 'auto',
            'autoScalingStrategy' => 'time', 'maxProcesses' => 10, 'maxTime' => 0, 'maxJobs' => 0,
            'memory' => 128, 'tries' => 3, 'timeout' => 90, 'nice' => 0,
        ],
    ],
    'environments' => [
        'production' => ['supervisor-core' => ['minProcesses' => 1, 'maxProcesses' => 20, 'balanceMaxShift' => 1, 'balanceCooldown' => 3]],
        'local' => ['supervisor-core' => ['minProcesses' => 1, 'maxProcesses' => 4]],
        'testing' => ['supervisor-core' => ['minProcesses' => 1, 'maxProcesses' => 2]],
    ],
];
