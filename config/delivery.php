<?php

return [
    'admission' => [
        'concurrency_enabled' => filter_var(
            env('DELIVERY_ADMISSION_CONCURRENCY_ENABLED', true),
            FILTER_VALIDATE_BOOL,
        ),
        'global_concurrency_limit' => env('DELIVERY_ADMISSION_GLOBAL_CONCURRENCY_LIMIT'),
        'workspace_concurrency_limit' => env('DELIVERY_ADMISSION_WORKSPACE_CONCURRENCY_LIMIT'),
        'reservation_ttl_seconds' => env('DELIVERY_ADMISSION_RESERVATION_TTL_SECONDS'),
    ],
];
