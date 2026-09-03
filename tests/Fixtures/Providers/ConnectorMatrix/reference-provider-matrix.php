<?php

return [
    'amazon-ses' => [
        'connector_class' => 'delivery',
        'auth_family' => 'aws_sigv4',
        'secret_reference_required' => true,
        'readiness_dimensions' => ['account', 'region', 'sandbox_status', 'sending_identity'],
        'quota_dimensions' => [
            ['scope' => 'account_region', 'unit' => 'recipient', 'window' => 'rolling_24h', 'runtime' => true],
            ['scope' => 'account_region', 'unit' => 'recipient', 'window' => 'second', 'runtime' => true],
        ],
        'sandbox' => [
            'supported' => true,
            'acceptance_means_delivery' => false,
            'requires_restricted_targets' => true,
            'production_readiness_evidence' => false,
        ],
        'send' => [
            'supported' => true,
            'mailbox_only' => false,
            'bulk_marketing_semantics' => false,
            'provider_native_idempotency_proven' => false,
            'ambiguous_outcome_requires_reconciliation' => true,
        ],
        'webhook' => [
            'advertised' => false,
            'verifier_strategies' => [],
        ],
        'unsupported_phase_capabilities' => ['routing.failover', 'social.publish'],
    ],
    'brevo' => [
        'connector_class' => 'delivery_marketing_platform',
        'auth_family' => 'api_key_or_oauth',
        'secret_reference_required' => true,
        'readiness_dimensions' => ['account', 'sender', 'auth', 'sandbox_behavior'],
        'quota_dimensions' => [
            ['scope' => 'endpoint_plan', 'unit' => 'request', 'window' => 'second', 'runtime' => true],
            ['scope' => 'endpoint_plan', 'unit' => 'request', 'window' => 'hour', 'runtime' => true],
        ],
        'sandbox' => [
            'supported' => true,
            'acceptance_means_delivery' => false,
            'request_validation_only' => true,
            'creates_delivery_log' => false,
            'production_readiness_evidence' => false,
        ],
        'send' => [
            'supported' => true,
            'mailbox_only' => false,
            'bulk_marketing_semantics' => true,
            'provider_native_idempotency_proven' => false,
            'ambiguous_outcome_requires_reconciliation' => true,
        ],
        'webhook' => [
            'advertised' => true,
            'verifier_strategies' => ['source_ip', 'basic_auth', 'bearer', 'configured_header'],
        ],
        'unsupported_phase_capabilities' => ['routing.failover', 'social.publish'],
    ],
    'gmail-api' => [
        'connector_class' => 'mailbox',
        'auth_family' => 'oauth2_delegated_user',
        'secret_reference_required' => true,
        'readiness_dimensions' => ['cloud_project', 'user', 'granted_scopes', 'oauth_verification', 'credential_lifecycle'],
        'least_privilege_send_scope' => 'https://www.googleapis.com/auth/gmail.send',
        'quota_dimensions' => [
            ['scope' => 'project', 'unit' => 'quota_unit', 'window' => 'provider_defined', 'runtime' => true],
            ['scope' => 'user', 'unit' => 'quota_unit', 'window' => 'provider_defined', 'runtime' => true],
        ],
        'sandbox' => [
            'supported' => false,
            'acceptance_means_delivery' => false,
            'production_readiness_evidence' => false,
        ],
        'send' => [
            'supported' => true,
            'mailbox_only' => true,
            'bulk_marketing_semantics' => false,
            'mime_rfc2822_base64url' => true,
            'provider_native_idempotency_proven' => false,
            'ambiguous_outcome_requires_reconciliation' => true,
        ],
        'webhook' => [
            'advertised' => false,
            'verifier_strategies' => [],
        ],
        'unsupported_phase_capabilities' => ['routing.failover', 'campaign.fanout', 'social.publish'],
    ],
];
