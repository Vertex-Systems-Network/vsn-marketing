<?php

namespace App\Modules\Publishing\Domain\Scheduling;

use App\Modules\Publishing\Domain\Campaign\CampaignPayloadGuard;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class CampaignScheduleExecutionIntent
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
        public string $claimId,
        public int $claimVersion,
        public DateTimeImmutable $resolvedAtUtc,
        public DateTimeImmutable $claimedAt,
        public DateTimeImmutable $emittedAt,
        public string $outboxId,
        public string $intentHash,
    ) {
        foreach ([
            'id' => $this->id,
            'workspaceId' => $this->workspaceId,
            'campaignId' => $this->campaignId,
            'snapshotId' => $this->snapshotId,
            'scheduleId' => $this->scheduleId,
            'scheduledApprovalId' => $this->scheduledApprovalId,
            'evaluatedApprovalId' => $this->evaluatedApprovalId,
            'claimId' => $this->claimId,
            'outboxId' => $this->outboxId,
        ] as $field => $value) {
            CampaignPayloadGuard::assertIdentifier($value, 'scheduleExecutionIntent.'.$field);
        }

        CampaignPayloadGuard::assertSha256($this->scheduleHash, 'scheduleExecutionIntent.scheduleHash');
        CampaignPayloadGuard::assertSha256($this->intentHash, 'scheduleExecutionIntent.intentHash');

        if ($this->claimVersion < 1) {
            throw new InvalidArgumentException('Campaign schedule execution intent claim version must be at least 1.');
        }

        foreach ([
            'resolvedAtUtc' => $this->resolvedAtUtc,
            'claimedAt' => $this->claimedAt,
            'emittedAt' => $this->emittedAt,
        ] as $field => $value) {
            if ($value->getOffset() !== 0) {
                throw new InvalidArgumentException("Campaign schedule execution intent {$field} must be normalized to UTC.");
            }
        }

        if (
            $this->emittedAt < $this->claimedAt
            || $this->claimedAt->format('U.u') !== $this->resolvedAtUtc->format('U.u')
        ) {
            throw new InvalidArgumentException(
                'Campaign schedule execution intent must originate from the exact canonical due occurrence.',
            );
        }

        $expectedHash = CampaignPayloadGuard::hash($this->canonicalPayload());
        if (! hash_equals($expectedHash, $this->intentHash)) {
            throw new InvalidArgumentException('Campaign schedule execution intent hash does not match canonical evidence.');
        }
    }

    public static function create(
        string $id,
        CampaignSchedule $schedule,
        CampaignScheduleDueClaim $claim,
        string $outboxId,
        DateTimeImmutable $emittedAt,
    ): self {
        if (
            $claim->workspaceId !== $schedule->workspaceId
            || $claim->campaignId !== $schedule->campaignId
            || $claim->snapshotId !== $schedule->snapshotId
            || $claim->scheduleId !== $schedule->id
            || ! hash_equals($claim->scheduleHash, $schedule->scheduleHash)
            || $claim->scheduledApprovalId !== $schedule->approvalId
            || $claim->state !== CampaignScheduleDueClaimState::Leased
        ) {
            throw new InvalidArgumentException('Campaign schedule execution intent claim does not match schedule authority.');
        }

        $payload = [
            'workspace_id' => $schedule->workspaceId,
            'campaign_id' => $schedule->campaignId,
            'snapshot_id' => $schedule->snapshotId,
            'schedule_id' => $schedule->id,
            'schedule_hash' => $schedule->scheduleHash,
            'scheduled_approval_id' => $schedule->approvalId,
            'evaluated_approval_id' => $claim->evaluatedApprovalId,
            'claim_id' => $claim->id,
            'claim_version' => $claim->version,
            'resolved_at_utc' => $schedule->resolvedAtUtc->format('Y-m-d\TH:i:s.u\Z'),
            'claimed_at' => $claim->claimedAt->format('Y-m-d\TH:i:s.u\Z'),
            'emitted_at' => $emittedAt->format('Y-m-d\TH:i:s.u\Z'),
            'outbox_id' => $outboxId,
        ];

        return new self(
            id: $id,
            workspaceId: $schedule->workspaceId,
            campaignId: $schedule->campaignId,
            snapshotId: $schedule->snapshotId,
            scheduleId: $schedule->id,
            scheduleHash: $schedule->scheduleHash,
            scheduledApprovalId: $schedule->approvalId,
            evaluatedApprovalId: $claim->evaluatedApprovalId,
            claimId: $claim->id,
            claimVersion: $claim->version,
            resolvedAtUtc: $schedule->resolvedAtUtc,
            claimedAt: $claim->claimedAt,
            emittedAt: $emittedAt,
            outboxId: $outboxId,
            intentHash: CampaignPayloadGuard::hash($payload),
        );
    }

    /** @return array<string, mixed> */
    public function canonicalPayload(): array
    {
        return [
            'workspace_id' => $this->workspaceId,
            'campaign_id' => $this->campaignId,
            'snapshot_id' => $this->snapshotId,
            'schedule_id' => $this->scheduleId,
            'schedule_hash' => $this->scheduleHash,
            'scheduled_approval_id' => $this->scheduledApprovalId,
            'evaluated_approval_id' => $this->evaluatedApprovalId,
            'claim_id' => $this->claimId,
            'claim_version' => $this->claimVersion,
            'resolved_at_utc' => $this->resolvedAtUtc->format('Y-m-d\TH:i:s.u\Z'),
            'claimed_at' => $this->claimedAt->format('Y-m-d\TH:i:s.u\Z'),
            'emitted_at' => $this->emittedAt->format('Y-m-d\TH:i:s.u\Z'),
            'outbox_id' => $this->outboxId,
        ];
    }
}
