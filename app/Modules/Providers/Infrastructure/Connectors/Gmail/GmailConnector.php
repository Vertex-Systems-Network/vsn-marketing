<?php

namespace App\Modules\Providers\Infrastructure\Connectors\Gmail;

use App\Modules\Providers\Domain\CapabilitySupport;
use App\Modules\Providers\Domain\Connectors\ConnectorCapability;
use App\Modules\Providers\Domain\Connectors\ConnectorManifest;
use App\Modules\Providers\Domain\Connectors\Contracts\ConnectorAdapter;
use App\Modules\Providers\Domain\ProviderReadinessStatus;
use DateTimeImmutable;

final readonly class GmailConnector implements ConnectorAdapter
{
    public const SEND_SCOPE = 'https://www.googleapis.com/auth/gmail.send';

    public function __construct(
        private DateTimeImmutable $observedAt,
        private string $sourceVersion = 'gmail-v1',
    ) {}

    public function manifest(): ConnectorManifest
    {
        return new ConnectorManifest(
            connectorKey: 'gmail-api',
            connectorVersion: '1.0.0',
            apiVersionStrategy: 'gmail-v1-rest',
            documentationUrl: 'https://developers.google.com/workspace/gmail/api/reference/rest/v1/users.messages/send',
            observedAt: $this->observedAt,
            sourceVersion: $this->sourceVersion,
            sandboxLimitations: [
                'Gmail API does not expose an equivalent delivery sandbox for messages.send.',
                'Deterministic tests must use fixtures/fakes; live integration credentials require separately controlled provider access.',
            ],
            capabilities: [
                new ConnectorCapability(
                    operation: 'email.send',
                    support: CapabilitySupport::Supported,
                    readinessStates: [ProviderReadinessStatus::Ready],
                    constraints: [
                        'delivery_mode' => 'delegated_mailbox',
                        'bulk_marketing' => false,
                        'required_scope' => self::SEND_SCOPE,
                        'message_format' => 'rfc2822-mime-base64url',
                        'provider_idempotency_token' => false,
                    ],
                ),
                new ConnectorCapability(
                    operation: 'quota.observe',
                    support: CapabilitySupport::Supported,
                    readinessStates: [ProviderReadinessStatus::Ready],
                    constraints: [
                        'unit' => 'quota_unit',
                        'scopes' => ['project', 'user'],
                        'quota_model_changed_at' => '2026-05-01',
                        'project_cohort_provenance_required' => true,
                    ],
                ),
                new ConnectorCapability(
                    operation: 'webhook.verify',
                    support: CapabilitySupport::Unsupported,
                    readinessStates: [],
                    constraints: [
                        'reason' => 'This TASK-0017 reference implementation is send-only; unrelated mailbox change/push mechanisms are not implied.',
                    ],
                ),
            ],
            metadata: [
                'provider_class' => 'mailbox',
                'bulk_marketing_provider' => false,
                'oauth_least_privilege_scope' => self::SEND_SCOPE,
                'credentials' => 'secret-reference-only',
                'acceptance_is_delivery' => false,
            ],
        );
    }
}
