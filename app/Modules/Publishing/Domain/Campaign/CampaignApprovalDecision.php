<?php

namespace App\Modules\Publishing\Domain\Campaign;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class CampaignApprovalDecision
{
    /** @param list<string> $capabilityEvidenceIds */
    public function __construct(
        public string $id,
        public string $workspaceId,
        public string $campaignId,
        public string $snapshotId,
        public string $targetSetHash,
        public CampaignApprovalOutcome $outcome,
        public string $actorId,
        public string $actorRole,
        public ?string $reason,
        public array $capabilityEvidenceIds,
        public ?string $supersedesDecisionId,
        public ?DateTimeImmutable $expiresAt,
        public string $idempotencyKey,
        public DateTimeImmutable $occurredAt,
    ) {
        CampaignPayloadGuard::assertIdentifier($this->id, 'approval.id');
        CampaignPayloadGuard::assertIdentifier($this->workspaceId, 'approval.workspaceId');
        CampaignPayloadGuard::assertIdentifier($this->campaignId, 'approval.campaignId');
        CampaignPayloadGuard::assertIdentifier($this->snapshotId, 'approval.snapshotId');
        CampaignPayloadGuard::assertIdentifier($this->actorId, 'approval.actorId');
        CampaignPayloadGuard::assertIdentifier($this->actorRole, 'approval.actorRole', 120);
        CampaignPayloadGuard::assertIdentifier($this->idempotencyKey, 'approval.idempotencyKey');
        CampaignPayloadGuard::assertSha256($this->targetSetHash, 'approval.targetSetHash');
        CampaignPayloadGuard::assertIdentifierList($this->capabilityEvidenceIds, 'approval.capabilityEvidenceIds');

        if ($this->reason !== null && mb_strlen($this->reason) > 2000) {
            throw new InvalidArgumentException('Campaign approval reason must not exceed 2000 characters.');
        }

        if ($this->outcome === CampaignApprovalOutcome::Revoked) {
            if ($this->supersedesDecisionId === null) {
                throw new InvalidArgumentException('Campaign approval revocation must reference the superseded decision.');
            }
        } elseif ($this->supersedesDecisionId !== null) {
            throw new InvalidArgumentException('Only a campaign approval revocation may supersede a prior decision.');
        }

        if ($this->supersedesDecisionId !== null) {
            CampaignPayloadGuard::assertIdentifier($this->supersedesDecisionId, 'approval.supersedesDecisionId');
        }

        if ($this->expiresAt !== null && $this->expiresAt <= $this->occurredAt) {
            throw new InvalidArgumentException('Campaign approval expiry must be later than the decision time.');
        }
    }
}
