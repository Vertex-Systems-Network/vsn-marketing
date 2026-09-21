<?php

namespace App\Modules\Publishing\Domain\Campaign;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class CampaignEvent
{
    public const string CREATED = 'campaign.created';

    public const string LIFECYCLE_TRANSITIONED = 'campaign.lifecycle.transitioned';

    public const string SNAPSHOT_CREATED = 'campaign.snapshot.created';

    public const string APPROVAL_RECORDED = 'campaign.approval.recorded';

    /** @param array<string, mixed> $evidence */
    public function __construct(
        public string $id,
        public string $workspaceId,
        public string $campaignId,
        public ?string $snapshotId,
        public string $type,
        public string $actorId,
        public ?string $reason,
        public ?CampaignStatus $fromStatus,
        public ?CampaignStatus $toStatus,
        public array $evidence,
        public string $idempotencyKey,
        public DateTimeImmutable $occurredAt,
    ) {
        CampaignPayloadGuard::assertIdentifier($this->id, 'event.id');
        CampaignPayloadGuard::assertIdentifier($this->workspaceId, 'event.workspaceId');
        CampaignPayloadGuard::assertIdentifier($this->campaignId, 'event.campaignId');
        CampaignPayloadGuard::assertIdentifier($this->type, 'event.type', 120);
        CampaignPayloadGuard::assertIdentifier($this->actorId, 'event.actorId');
        CampaignPayloadGuard::assertIdentifier($this->idempotencyKey, 'event.idempotencyKey');

        if ($this->snapshotId !== null) {
            CampaignPayloadGuard::assertIdentifier($this->snapshotId, 'event.snapshotId');
        }

        if ($this->reason !== null && mb_strlen($this->reason) > 2000) {
            throw new InvalidArgumentException('Campaign event reason must not exceed 2000 characters.');
        }

        CampaignPayloadGuard::assertPublicJson($this->evidence, 'event.evidence');

        if ($this->type === self::CREATED) {
            if ($this->fromStatus !== null || $this->toStatus !== CampaignStatus::Draft) {
                throw new InvalidArgumentException('Campaign creation event must initialize draft status.');
            }

            return;
        }

        if ($this->type === self::LIFECYCLE_TRANSITIONED) {
            if ($this->fromStatus === null || $this->toStatus === null || ! $this->fromStatus->canTransitionTo($this->toStatus)) {
                throw new InvalidArgumentException('Campaign lifecycle event contains an invalid transition.');
            }

            return;
        }

        if ($this->fromStatus !== null || $this->toStatus !== null) {
            throw new InvalidArgumentException('Non-lifecycle campaign events cannot carry lifecycle transition states.');
        }
    }

    /** @param array<string, mixed> $evidence */
    public static function created(
        Campaign $campaign,
        string $id,
        string $actorId,
        ?string $reason,
        array $evidence,
        string $idempotencyKey,
        DateTimeImmutable $occurredAt,
    ): self {
        return new self(
            id: $id,
            workspaceId: $campaign->workspaceId,
            campaignId: $campaign->id,
            snapshotId: null,
            type: self::CREATED,
            actorId: $actorId,
            reason: $reason,
            fromStatus: null,
            toStatus: CampaignStatus::Draft,
            evidence: $evidence,
            idempotencyKey: $idempotencyKey,
            occurredAt: $occurredAt,
        );
    }

    /** @param array<string, mixed> $evidence */
    public static function transitioned(
        Campaign $before,
        Campaign $after,
        string $id,
        string $actorId,
        ?string $reason,
        array $evidence,
        string $idempotencyKey,
        DateTimeImmutable $occurredAt,
    ): self {
        if (
            $before->id !== $after->id
            || $before->workspaceId !== $after->workspaceId
            || $after->stateVersion !== $before->stateVersion + 1
        ) {
            throw new InvalidArgumentException('Campaign transition event requires consecutive versions of the same campaign.');
        }

        return new self(
            id: $id,
            workspaceId: $before->workspaceId,
            campaignId: $before->id,
            snapshotId: null,
            type: self::LIFECYCLE_TRANSITIONED,
            actorId: $actorId,
            reason: $reason,
            fromStatus: $before->status,
            toStatus: $after->status,
            evidence: $evidence,
            idempotencyKey: $idempotencyKey,
            occurredAt: $occurredAt,
        );
    }

    /** @param array<string, mixed> $evidence */
    public static function snapshotCreated(
        CampaignSnapshot $snapshot,
        string $id,
        string $actorId,
        ?string $reason,
        array $evidence,
        string $idempotencyKey,
        DateTimeImmutable $occurredAt,
    ): self {
        return new self(
            id: $id,
            workspaceId: $snapshot->workspaceId,
            campaignId: $snapshot->campaignId,
            snapshotId: $snapshot->id,
            type: self::SNAPSHOT_CREATED,
            actorId: $actorId,
            reason: $reason,
            fromStatus: null,
            toStatus: null,
            evidence: $evidence,
            idempotencyKey: $idempotencyKey,
            occurredAt: $occurredAt,
        );
    }

    /** @param array<string, mixed> $evidence */
    public static function approvalRecorded(
        CampaignApprovalDecision $decision,
        string $id,
        array $evidence,
        string $idempotencyKey,
    ): self {
        return new self(
            id: $id,
            workspaceId: $decision->workspaceId,
            campaignId: $decision->campaignId,
            snapshotId: $decision->snapshotId,
            type: self::APPROVAL_RECORDED,
            actorId: $decision->actorId,
            reason: $decision->reason,
            fromStatus: null,
            toStatus: null,
            evidence: $evidence,
            idempotencyKey: $idempotencyKey,
            occurredAt: $decision->occurredAt,
        );
    }
}
