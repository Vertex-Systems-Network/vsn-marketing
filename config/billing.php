<?php

/*
 * ADR-0006: Stripe in test mode only. Live charges are hard-disabled in code;
 * enabling them requires a new owner decision and code change, not an env flag.
 */
return [
    'provider' => 'stripe',
    'mode' => 'test',
    'webhook_secret' => env('STRIPE_TEST_WEBHOOK_SECRET', ''),
    'webhook_tolerance_seconds' => 300,
    'max_evidence_age_days' => 35,
    'plans' => [
        'starter' => ['messages.sent' => 10000, 'contacts.stored' => 5000, 'ai.tokens' => 200000],
        'growth' => ['messages.sent' => 100000, 'contacts.stored' => 50000, 'ai.tokens' => 2000000],
    ],
];
