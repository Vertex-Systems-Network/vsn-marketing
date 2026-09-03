<?php

namespace App\Modules\Providers\Infrastructure\Connectors\Brevo;

use App\Modules\Providers\Domain\CapabilitySupport;
use App\Modules\Providers\Domain\Connectors\ConnectorCapability;
use App\Modules\Providers\Domain\Connectors\ConnectorManifest;
use App\Modules\Providers\Domain\Connectors\Contracts\ConnectorAdapter;
use App\Modules\Providers\Domain\ProviderReadinessStatus;
use DateTimeImmutable;

final readonly class BrevoConnector implements ConnectorAdapter
{
    public function __construct(
        private DateTimeImmutable $observedAt,
        private string $sourceVersion = 'v3',
    ) {}

    public function manifest(): ConnectorManifest
    {
        return new ConnectorManifest(
            connectorKey: 'brevo',
            connectorVersion: '1.0.0',
            apiVersionStrategy: 'brevo-v3-endpoint',
            documentationUrl: 'https://developers.brevo.com/reference/sendtransacemail',
            observedAt: $this->observedAt,
            sourceVersion: $this->sourceVersion,
            sandboxLimitations: [
                'Sandbox mode uses X-Sib-Sandbox: drop and validates the request without delivering email.',
                'A sandbox response may include a messageId even though no email is sent and no email log is created.',
            ],
            capabilities: [
                new ConnectorCapability(
                    operation: 'email.send',
                    support: CapabilitySupport::Supported,
                    readinessStates: [
                        ProviderReadinessStatus::SandboxOnly,
                        ProviderReadinessStatus::Ready,
                    ],
                    constraints: [
                        'sandbox_header' => 'X-Sib-Sandbox: drop',
                        'sandbox_delivers' => false,
                        'sandbox_creates_email_log' => false,
                        'provider_idempotency_token' => false,
                    ],
                ),
                new ConnectorCapability(
                    operation: 'quota.observe',
                    support: CapabilitySupport::Supported,
                    readinessStates: [
                        ProviderReadinessStatus::SandboxOnly,
                        ProviderReadinessStatus::Ready,
                    ],
                    constraints: [
                        'headers' => [
                            'x-sib-ratelimit-limit',
                            'x-sib-ratelimit-remaining',
                            'x-sib-ratelimit-reset',
                        ],
                        'units' => ['rps', 'rph'],
                    ],
                ),
                new ConnectorCapability(
                    operation: 'webhook.verify',
                    support: CapabilitySupport::Supported,
                    readinessStates: [ProviderReadinessStatus::Ready],
                    constraints: [
                        'strategies' => ['source_ip', 'basic', 'bearer', 'custom_headers'],
                        'universal_hmac_assumed' => false,
                    ],
                ),
            ],
            metadata: [
                'provider_class' => 'delivery_marketing_platform',
                'acceptance_is_delivery' => false,
                'credentials' => 'secret-reference-only',
                'rate_limits' => 'endpoint-and-plan-specific',
            ],
        );
    }
}
