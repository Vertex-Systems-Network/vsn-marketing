<?php

return [
    'scim_deactivation_enabled' => env('ENTERPRISE_SCIM_DEACTIVATION_ENABLED', false),
    'oidc' => [
        'enabled' => env('ENTERPRISE_OIDC_ENABLED', false),
        'workspace_id' => env('ENTERPRISE_OIDC_WORKSPACE_ID', ''),
        'issuer' => env('ENTERPRISE_OIDC_ISSUER', ''),
        'client_id' => env('ENTERPRISE_OIDC_CLIENT_ID', ''),
        'client_secret' => env('ENTERPRISE_OIDC_CLIENT_SECRET', ''),
        'redirect_uri' => env('ENTERPRISE_OIDC_REDIRECT_URI', ''),
        'key_id' => env('ENTERPRISE_OIDC_KEY_ID', ''),
        'public_key_pem' => base64_decode((string) env('ENTERPRISE_OIDC_PUBLIC_KEY_PEM_BASE64', ''), true),
    ],
];
