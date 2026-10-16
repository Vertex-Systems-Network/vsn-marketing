<?php

/*
 * ADR-0006: EU-only processing. Every surface must declare its actual region;
 * an unset or non-EU value fails closed. AI and external provider egress are
 * disabled unless explicitly attested as EU.
 */
return [
    'supported_regions' => ['eu'],
    'default_region' => 'eu',
    'surfaces' => [
        'database' => env('RESIDENCY_DATABASE_REGION'),
        'queue' => env('RESIDENCY_QUEUE_REGION'),
        'files' => env('RESIDENCY_FILES_REGION'),
        'logs' => env('RESIDENCY_LOGS_REGION'),
        'backups' => env('RESIDENCY_BACKUPS_REGION'),
        'analytics' => env('RESIDENCY_ANALYTICS_REGION'),
        'ai_egress' => env('RESIDENCY_AI_EGRESS_REGION'),
        'provider_egress' => env('RESIDENCY_PROVIDER_EGRESS_REGION'),
    ],
];
