<?php

return [
    'object_store' => [
        'disk' => env('OBJECT_STORAGE_DISK', env('FILESYSTEM_DISK', 's3')),
    ],
    'outbox' => [
        'queue' => env('OUTBOX_QUEUE', 'outbox'),
        'batch_size' => (int) env('OUTBOX_BATCH_SIZE', 100),
        'lock_seconds' => (int) env('OUTBOX_LOCK_SECONDS', 120),
        'max_relay_attempts' => (int) env('OUTBOX_MAX_RELAY_ATTEMPTS', 10),
        'failure_retry_seconds' => (int) env('OUTBOX_FAILURE_RETRY_SECONDS', 900),
        'backoff' => [5, 30, 120, 300],
    ],
];
