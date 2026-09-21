<?php

namespace App\Modules\Publishing\Domain\Campaign;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class CampaignTargetBinding
{
    /** @param array<string, mixed> $metadata */
    public function __construct(
        public string $id,
        public string $workspaceId,
        public CampaignTargetKind $kind,
        public string $canonicalReferenceId,
        public string $channel,
        public ?string $providerConnectionId,
        public ?string $capabilityEvidenceId,
        public array $metadata,
        public DateTimeImmutable $createdAt,
    ) {
        CampaignPayloadGuard::assertIdentifier($this->id, 'target.id');
        CampaignPayloadGuard::assertIdentifier($this->workspaceId, 'target.workspaceId');
        CampaignPayloadGuard::assertIdentifier($this->canonicalReferenceId, 'target.canonicalReferenceId');
        CampaignPayloadGuard::assertIdentifier($this->channel, 'target.channel', 32);

        if ($this->kind === CampaignTargetKind::ProviderConnection) {
            if ($this->providerConnectionId === null || $this->providerConnectionId !== $this->canonicalReferenceId) {
                throw new InvalidArgumentException('Provider campaign targets must bind to the exact canonical provider connection id.');
            }

            CampaignPayloadGuard::assertIdentifier($this->providerConnectionId, 'target.providerConnectionId');
        } elseif ($this->providerConnectionId !== null || $this->capabilityEvidenceId !== null) {
            throw new InvalidArgumentException('Recipient campaign targets cannot embed provider routing or capability authority.');
        }

        if ($this->capabilityEvidenceId !== null) {
            CampaignPayloadGuard::assertIdentifier($this->capabilityEvidenceId, 'target.capabilityEvidenceId');
        }

        CampaignPayloadGuard::assertPublicJson($this->metadata, 'target.metadata');
    }

    /** @return array<string, mixed> */
    public function canonicalPayload(): array
    {
        return [
            'kind' => $this->kind->value,
            'canonical_reference_id' => $this->canonicalReferenceId,
            'channel' => $this->channel,
            'provider_connection_id' => $this->providerConnectionId,
            'capability_evidence_id' => $this->capabilityEvidenceId,
            'metadata' => $this->metadata,
        ];
    }

    public function fingerprint(): string
    {
        return CampaignPayloadGuard::hash($this->canonicalPayload());
    }
}
