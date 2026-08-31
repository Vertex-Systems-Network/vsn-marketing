<?php

return [
    /*
     |--------------------------------------------------------------------------
     | Connectors configuration
     |--------------------------------------------------------------------------
     |
     | Configure global connector behaviour such as webhook secrets and dedup store.
     |
     */

    'webhook_secret' => env('CONNECTORS_WEBHOOK_SECRET', null),

    // supported: 'in_memory', 'redis', 'database'
    'dedup_store' => env('CONNECTORS_DEDUP_STORE', 'in_memory'),

    // Dedup TTL default in seconds
    'dedup_ttl' => env('CONNECTORS_DEDUP_TTL', 300),
];
