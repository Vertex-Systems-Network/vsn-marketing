<?php

return [
    'object_store' => [
        'disk' => env('OBJECT_STORE_DISK', env('FILESYSTEM_DISK', 's3')),
    ],

    'outbox' => [
        'queue' => env('OUTBOX_QUEUE', 'outbox'),
        'batch_size' => (int) env('OUTBOX_BATCH_SIZE', 100),
        'scan_lock_seconds' => (int) env('OUTBOX_SCAN_LOCK_SECONDS', 55),
    ],

    'delivery_admission' => [
        'coordination_enabled' => filter_var(
            env('DELIVERY_ADMISSION_COORDINATION_ENABLED', env('APP_ENV') !== 'testing'),
            FILTER_VALIDATE_BOOL,
        ),
        'global_concurrency_limit' => (int) env(
            'DELIVERY_ADMISSION_GLOBAL_CONCURRENCY_LIMIT',
            env('HORIZON_MAX_PROCESSES', 10),
        ),
        'workspace_concurrency_limit' => (int) env('DELIVERY_ADMISSION_WORKSPACE_CONCURRENCY_LIMIT', 1),
        'reservation_ttl_seconds' => (int) env('DELIVERY_ADMISSION_RESERVATION_TTL_SECONDS', 120),
    ],
];
