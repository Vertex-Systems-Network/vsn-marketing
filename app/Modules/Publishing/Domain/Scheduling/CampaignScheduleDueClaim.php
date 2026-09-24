<?php

namespace App\Modules\Publishing\Domain\Scheduling;

use App\Modules\Publishing\Domain\Campaign\CampaignPayloadGuard;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class CampaignScheduleDueClaim
{
    public function __construct(
        public string $id,
        public string $workspaceId,
        public string $campaignId,
        public string $snapshotId,
        public string $scheduleId,
        public string $scheduleHash,
        public string $scheduledApprovalId,
        public string $evaluatedApprovalId,
        public CampaignScheduleDueClaimState $state,
        public string $leaseOwner,
        public string $leaseTokenHash,
        public DateTimeImmutable $leaseExpiresAt,
        public DateTimeImmutable $claimedAt,
        public int $attemptNumber,
        public int $version,
        public DateTimeImmutable $updatedAt,
    ) {
        CampaignPayloadGuard::assertIdentifier($this->id, 'scheduleClaim.id');
        CampaignPayloadGuard::assertIdentifier($this->workspaceId, 'scheduleClaim.workspaceId');
        CampaignPayloadGuard::assertIdentifier($this->campaignId, 'scheduleClaim.campaignId');
        CampaignPayloadGuard::assertIdentifier($this->snapshotId, 'scheduleClaim.snapshotId');
        CampaignPayloadGuard::assertIdentifier($this->scheduleId, 'scheduleClaim.scheduleId');
        CampaignPayloadGuard::assertIdentifier($this->scheduledApprovalId, 'scheduleClaim.scheduledApprovalId');
        CampaignPayloadGuard::assertIdentifier($this->evaluatedApprovalId, 'scheduleClaim.evaluatedApprovalId');
        CampaignPayloadGuard::assertIdentifier($this->leaseOwner, 'scheduleClaim.leaseOwner');
        CampaignPayloadGuard::assertSha256($this->leaseTokenHash, 'scheduleClaim.leaseTokenHash');
        CampaignPayloadGuard::assertSha256($this->scheduleHash, 'scheduleClaim.scheduleHash');

        foreach ([
            'leaseExpiresAt' => $this->leaseExpiresAt,
            'claimedAt' => $this->claimedAt,
            'updatedAt' => $this->updatedAt,
        ] as $field => $value) {
            if ($value->getOffset() !== 0) {
                throw new InvalidArgumentException("Campaign schedule claim {$field} must be normalized to UTC.");
            }
        }

        if ($this->attemptNumber < 1 || $this->version < 1) {
            throw new InvalidArgumentException('Campaign schedule claim attempt/version must be at least 1.');
        }

        if ($this->leaseExpiresAt <= $this->updatedAt) {
            throw new InvalidArgumentException('Campaign schedule claim lease must expire after its latest coordination update.');
        }

        if ($this->updatedAt < $this->claimedAt) {
            throw new InvalidArgumentException('Campaign schedule claim update cannot predate the original claim.');
        }
    }

    public static function firstLease(
        string $id,
        CampaignSchedule $schedule,
        string $evaluatedApprovalId,
        string $leaseOwner,
        string $leaseToken,
        DateTimeImmutable $claimedAt,
        DateTimeImmutable $leaseExpiresAt,
    ): self {
        return new self(
            id: $id,
            workspaceId: $schedule->workspaceId,
            campaignId: $schedule->campaignId,
            snapshotId: $schedule->snapshotId,
            scheduleId: $schedule->id,
            scheduleHash: $schedule->scheduleHash,
            scheduledApprovalId: $schedule->approvalId,
            evaluatedApprovalId: $evaluatedApprovalId,
            state: CampaignScheduleDueClaimState::Leased,
            leaseOwner: $leaseOwner,
            leaseTokenHash: hash('sha256', $leaseToken),
            leaseExpiresAt: $leaseExpiresAt,
            claimedAt: $claimedAt,
            attemptNumber: 1,
            version: 1,
            updatedAt: $claimedAt,
        );
    }

    public function replayedBy(string $leaseOwner, string $leaseToken): bool
    {
        return $this->leaseOwner === $leaseOwner
            && hash_equals($this->leaseTokenHash, hash('sha256', $leaseToken));
    }

    public function isLeaseActiveAt(DateTimeImmutable $at): bool
    {
        return $this->state === CampaignScheduleDueClaimState::Leased && $this->leaseExpiresAt > $at;
    }

    public function takeover(
        string $leaseOwner,
        string $leaseToken,
        DateTimeImmutable $at,
        DateTimeImmutable $leaseExpiresAt,
    ): self {
        if ($this->state !== CampaignScheduleDueClaimState::Leased || $this->leaseExpiresAt > $at) {
            throw new InvalidArgumentException('Campaign schedule claim cannot be taken over before its active lease expires.');
        }

        return new self(
            id: $this->id,
            workspaceId: $this->workspaceId,
            campaignId: $this->campaignId,
            snapshotId: $this->snapshotId,
            scheduleId: $this->scheduleId,
            scheduleHash: $this->scheduleHash,
            scheduledApprovalId: $this->scheduledApprovalId,
            evaluatedApprovalId: $this->evaluatedApprovalId,
            state: CampaignScheduleDueClaimState::Leased,
            leaseOwner: $leaseOwner,
            leaseTokenHash: hash('sha256', $leaseToken),
            leaseExpiresAt: $leaseExpiresAt,
            claimedAt: $this->claimedAt,
            attemptNumber: $this->attemptNumber + 1,
            version: $this->version + 1,
            updatedAt: $at,
        );
    }

    public function emittedAt(DateTimeImmutable $at): self
    {
        if ($this->state !== CampaignScheduleDueClaimState::Leased) {
            return $this;
        }

        return new self(
            id: $this->id,
            workspaceId: $this->workspaceId,
            campaignId: $this->campaignId,
            snapshotId: $this->snapshotId,
            scheduleId: $this->scheduleId,
            scheduleHash: $this->scheduleHash,
            scheduledApprovalId: $this->scheduledApprovalId,
            evaluatedApprovalId: $this->evaluatedApprovalId,
            state: CampaignScheduleDueClaimState::Emitted,
            leaseOwner: $this->leaseOwner,
            leaseTokenHash: $this->leaseTokenHash,
            leaseExpiresAt: $this->leaseExpiresAt,
            claimedAt: $this->claimedAt,
            attemptNumber: $this->attemptNumber,
            version: $this->version + 1,
            updatedAt: $at,
        );
    }
}
