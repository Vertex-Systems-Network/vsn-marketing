<?php

namespace App\Modules\Publishing\Domain\Publication;

use App\Modules\Publishing\Domain\Campaign\CampaignPayloadGuard;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class PublicationAttempt
{
    public function __construct(
        public string $id,
        public string $workspaceId,
        public string $executionIntentId,
        public string $campaignId,
        public string $snapshotId,
        public string $targetId,
        public string $targetHash,
        public string $channel,
        public string $providerConnectionId,
        public string $capabilityEvidenceId,
        public string $providerId,
        public string $idempotencyKey,
        public PublicationAttemptState $state,
        public int $stateVersion,
        public string $attemptHash,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {
        foreach ([
            'id' => $this->id,
            'workspaceId' => $this->workspaceId,
            'executionIntentId' => $this->executionIntentId,
            'campaignId' => $this->campaignId,
            'snapshotId' => $this->snapshotId,
            'targetId' => $this->targetId,
            'channel' => $this->channel,
            'providerConnectionId' => $this->providerConnectionId,
            'capabilityEvidenceId' => $this->capabilityEvidenceId,
            'providerId' => $this->providerId,
        ] as $field => $value) {
            CampaignPayloadGuard::assertIdentifier($value, 'publicationAttempt.'.$field, $field === 'channel' ? 32 : 191);
        }

        CampaignPayloadGuard::assertSha256($this->targetHash, 'publicationAttempt.targetHash');
        CampaignPayloadGuard::assertSha256($this->idempotencyKey, 'publicationAttempt.idempotencyKey');
        CampaignPayloadGuard::assertSha256($this->attemptHash, 'publicationAttempt.attemptHash');

        if ($this->stateVersion < 1) {
            throw new InvalidArgumentException('Publication attempt state version must be at least 1.');
        }

        if ($this->createdAt->getOffset() !== 0 || $this->updatedAt->getOffset() !== 0) {
            throw new InvalidArgumentException('Publication attempt timestamps must be normalized to UTC.');
        }

        if ($this->updatedAt < $this->createdAt) {
            throw new InvalidArgumentException('Publication attempt updated_at cannot precede created_at.');
        }

        if (! hash_equals(CampaignPayloadGuard::hash($this->canonicalIdentityPayload()), $this->attemptHash)) {
            throw new InvalidArgumentException('Publication attempt hash does not match canonical authority evidence.');
        }
    }

    public static function prepare(
        string $id,
        string $workspaceId,
        string $executionIntentId,
        string $campaignId,
        string $snapshotId,
        string $targetId,
        string $targetHash,
        string $channel,
        string $providerConnectionId,
        string $capabilityEvidenceId,
        string $providerId,
        DateTimeImmutable $createdAt,
    ): self {
        $idempotencyKey = CampaignPayloadGuard::hash([
            'operation' => 'publication.create',
            'workspace_id' => $workspaceId,
            'execution_intent_id' => $executionIntentId,
            'target_id' => $targetId,
        ]);

        $identity = [
            'operation' => 'publication.create',
            'workspace_id' => $workspaceId,
            'execution_intent_id' => $executionIntentId,
            'campaign_id' => $campaignId,
            'snapshot_id' => $snapshotId,
            'target_id' => $targetId,
            'target_hash' => $targetHash,
            'channel' => $channel,
            'provider_connection_id' => $providerConnectionId,
            'capability_evidence_id' => $capabilityEvidenceId,
            'provider_id' => $providerId,
            'idempotency_key' => $idempotencyKey,
        ];

        return new self(
            id: $id,
            workspaceId: $workspaceId,
            executionIntentId: $executionIntentId,
            campaignId: $campaignId,
            snapshotId: $snapshotId,
            targetId: $targetId,
            targetHash: $targetHash,
            channel: $channel,
            providerConnectionId: $providerConnectionId,
            capabilityEvidenceId: $capabilityEvidenceId,
            providerId: $providerId,
            idempotencyKey: $idempotencyKey,
            state: PublicationAttemptState::Prepared,
            stateVersion: 1,
            attemptHash: CampaignPayloadGuard::hash($identity),
            createdAt: $createdAt,
            updatedAt: $createdAt,
        );
    }

    public function transitionTo(PublicationAttemptState $next, DateTimeImmutable $at): self
    {
        if (! $this->state->canTransitionTo($next)) {
            throw new InvalidArgumentException(
                "Publication attempt cannot transition from {$this->state->value} to {$next->value}.",
            );
        }

        if ($at->getOffset() !== 0 || $at <= $this->updatedAt) {
            throw new InvalidArgumentException('Publication attempt transitions require a later UTC timestamp.');
        }

        return new self(
            id: $this->id,
            workspaceId: $this->workspaceId,
            executionIntentId: $this->executionIntentId,
            campaignId: $this->campaignId,
            snapshotId: $this->snapshotId,
            targetId: $this->targetId,
            targetHash: $this->targetHash,
            channel: $this->channel,
            providerConnectionId: $this->providerConnectionId,
            capabilityEvidenceId: $this->capabilityEvidenceId,
            providerId: $this->providerId,
            idempotencyKey: $this->idempotencyKey,
            state: $next,
            stateVersion: $this->stateVersion + 1,
            attemptHash: $this->attemptHash,
            createdAt: $this->createdAt,
            updatedAt: $at,
        );
    }

    /** @return array<string, string> */
    public function canonicalIdentityPayload(): array
    {
        return [
            'operation' => 'publication.create',
            'workspace_id' => $this->workspaceId,
            'execution_intent_id' => $this->executionIntentId,
            'campaign_id' => $this->campaignId,
            'snapshot_id' => $this->snapshotId,
            'target_id' => $this->targetId,
            'target_hash' => $this->targetHash,
            'channel' => $this->channel,
            'provider_connection_id' => $this->providerConnectionId,
            'capability_evidence_id' => $this->capabilityEvidenceId,
            'provider_id' => $this->providerId,
            'idempotency_key' => $this->idempotencyKey,
        ];
    }
}
