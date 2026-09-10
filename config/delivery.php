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
    'recovery' => [
        'retry_base_delay_seconds' => env('DELIVERY_RECOVERY_RETRY_BASE_DELAY_SECONDS', 5),
        'retry_max_delay_seconds' => env('DELIVERY_RECOVERY_RETRY_MAX_DELAY_SECONDS', 300),
        'breaker_failure_threshold' => env('DELIVERY_RECOVERY_BREAKER_FAILURE_THRESHOLD', 3),
        'breaker_open_seconds' => env('DELIVERY_RECOVERY_BREAKER_OPEN_SECONDS', 60),
        'reconciliation_max_probes' => env('DELIVERY_RECOVERY_RECONCILIATION_MAX_PROBES', 3),
    ],
];
