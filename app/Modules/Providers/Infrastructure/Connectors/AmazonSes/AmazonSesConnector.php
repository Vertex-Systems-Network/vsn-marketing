<?php

namespace App\Modules\Providers\Infrastructure\Connectors\AmazonSes;

use App\Modules\Providers\Domain\CapabilitySupport;
use App\Modules\Providers\Domain\Connectors\ConnectorCapability;
use App\Modules\Providers\Domain\Connectors\ConnectorManifest;
use App\Modules\Providers\Domain\Connectors\Contracts\ConnectorAdapter;
use App\Modules\Providers\Domain\ProviderReadinessStatus;
use DateTimeImmutable;

final readonly class AmazonSesConnector implements ConnectorAdapter
{
    public function __construct(
        private DateTimeImmutable $observedAt,
        private string $sourceVersion = 'ses-v2',
    ) {}

    public function manifest(): ConnectorManifest
    {
        return new ConnectorManifest(
            connectorKey: 'amazon-ses',
            connectorVersion: '1.0.0',
            apiVersionStrategy: 'ses-v2-region-endpoint',
            documentationUrl: 'https://docs.aws.amazon.com/ses/latest/APIReference-V2/Welcome.html',
            observedAt: $this->observedAt,
            sourceVersion: $this->sourceVersion,
            sandboxLimitations: [
                'Sandbox readiness is region-specific.',
                'Sandbox recipients must be verified identities or the SES mailbox simulator.',
                'Sandbox quota is provider-controlled and must be discovered/refreshed instead of treated as a product constant.',
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
                        'region_required' => true,
                        'verified_sending_identity_required' => true,
                        'sandbox_recipient_restrictions' => true,
                        'content_modes' => ['simple', 'raw', 'templated'],
                        'provider_idempotency_token' => false,
                    ],
                ),
                new ConnectorCapability(
                    operation: 'quota.read',
                    support: CapabilitySupport::Supported,
                    readinessStates: [
                        ProviderReadinessStatus::SandboxOnly,
                        ProviderReadinessStatus::Ready,
                    ],
                    constraints: [
                        'source' => 'GetAccount.SendQuota',
                        'region_scoped' => true,
                    ],
                ),
                new ConnectorCapability(
                    operation: 'webhook.verify',
                    support: CapabilitySupport::Unsupported,
                    readinessStates: [],
                    constraints: [
                        'reason' => 'SES event publishing is integration/configuration specific; no universal inbound verifier is advertised by this reference connector.',
                    ],
                ),
            ],
            metadata: [
                'provider_class' => 'delivery',
                'region_required' => true,
                'quota_model' => 'recipient-counted rolling-24h plus per-second rate',
                'acceptance_is_delivery' => false,
                'credentials' => 'secret-reference-only',
            ],
        );
    }
}
