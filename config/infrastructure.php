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
];
