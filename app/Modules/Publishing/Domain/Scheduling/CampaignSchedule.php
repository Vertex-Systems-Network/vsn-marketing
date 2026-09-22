<?php

namespace App\Modules\Publishing\Domain\Scheduling;

use App\Modules\Publishing\Domain\Campaign\CampaignPayloadGuard;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class CampaignSchedule
{
    public function __construct(
        public string $id,
        public string $workspaceId,
        public string $campaignId,
        public string $snapshotId,
        public string $approvalId,
        public string $targetSetHash,
        public CampaignScheduleStrategy $strategy,
        public string $timezoneId,
        public string $localScheduledAt,
        public DateTimeImmutable $resolvedAtUtc,
        public string $scheduleHash,
        public string $idempotencyKey,
        public string $createdByActorId,
        public DateTimeImmutable $createdAt,
    ) {
        CampaignPayloadGuard::assertIdentifier($this->id, 'schedule.id');
        CampaignPayloadGuard::assertIdentifier($this->workspaceId, 'schedule.workspaceId');
        CampaignPayloadGuard::assertIdentifier($this->campaignId, 'schedule.campaignId');
        CampaignPayloadGuard::assertIdentifier($this->snapshotId, 'schedule.snapshotId');
        CampaignPayloadGuard::assertIdentifier($this->approvalId, 'schedule.approvalId');
        CampaignPayloadGuard::assertIdentifier($this->timezoneId, 'schedule.timezoneId');
        CampaignPayloadGuard::assertIdentifier($this->localScheduledAt, 'schedule.localScheduledAt');
        CampaignPayloadGuard::assertIdentifier($this->idempotencyKey, 'schedule.idempotencyKey');
        CampaignPayloadGuard::assertIdentifier($this->createdByActorId, 'schedule.createdByActorId');
        CampaignPayloadGuard::assertSha256($this->targetSetHash, 'schedule.targetSetHash');
        CampaignPayloadGuard::assertSha256($this->scheduleHash, 'schedule.scheduleHash');

        if ($this->strategy !== CampaignScheduleStrategy::FixedInstant) {
            throw new InvalidArgumentException(
                'TASK-0039 calendar foundation only persists fixed_instant schedules; queue_next_slot requires a versioned queue rule.',
            );
        }

        if ($this->resolvedAtUtc->getOffset() !== 0) {
            throw new InvalidArgumentException('Campaign schedule resolvedAtUtc must be normalized to UTC.');
        }

        $expectedHash = CampaignPayloadGuard::hash($this->canonicalPayload());
        if (! hash_equals($expectedHash, $this->scheduleHash)) {
            throw new InvalidArgumentException('Campaign schedule hash does not match canonical schedule payload.');
        }
    }

    public static function fixedInstant(
        string $id,
        string $workspaceId,
        string $campaignId,
        string $snapshotId,
        string $approvalId,
        string $targetSetHash,
        string $timezoneId,
        string $localScheduledAt,
        DateTimeImmutable $resolvedAtUtc,
        string $idempotencyKey,
        string $createdByActorId,
        DateTimeImmutable $createdAt,
    ): self {
        $payload = self::canonicalPayloadFor(
            workspaceId: $workspaceId,
            campaignId: $campaignId,
            snapshotId: $snapshotId,
            approvalId: $approvalId,
            targetSetHash: $targetSetHash,
            strategy: CampaignScheduleStrategy::FixedInstant,
            timezoneId: $timezoneId,
            localScheduledAt: $localScheduledAt,
            resolvedAtUtc: $resolvedAtUtc,
        );

        return new self(
            id: $id,
            workspaceId: $workspaceId,
            campaignId: $campaignId,
            snapshotId: $snapshotId,
            approvalId: $approvalId,
            targetSetHash: $targetSetHash,
            strategy: CampaignScheduleStrategy::FixedInstant,
            timezoneId: $timezoneId,
            localScheduledAt: $localScheduledAt,
            resolvedAtUtc: $resolvedAtUtc,
            scheduleHash: CampaignPayloadGuard::hash($payload),
            idempotencyKey: $idempotencyKey,
            createdByActorId: $createdByActorId,
            createdAt: $createdAt,
        );
    }

    /** @return array<string, mixed> */
    public function canonicalPayload(): array
    {
        return self::canonicalPayloadFor(
            workspaceId: $this->workspaceId,
            campaignId: $this->campaignId,
            snapshotId: $this->snapshotId,
            approvalId: $this->approvalId,
            targetSetHash: $this->targetSetHash,
            strategy: $this->strategy,
            timezoneId: $this->timezoneId,
            localScheduledAt: $this->localScheduledAt,
            resolvedAtUtc: $this->resolvedAtUtc,
        );
    }

    /** @return array<string, mixed> */
    private static function canonicalPayloadFor(
        string $workspaceId,
        string $campaignId,
        string $snapshotId,
        string $approvalId,
        string $targetSetHash,
        CampaignScheduleStrategy $strategy,
        string $timezoneId,
        string $localScheduledAt,
        DateTimeImmutable $resolvedAtUtc,
    ): array {
        return [
            'workspace_id' => $workspaceId,
            'campaign_id' => $campaignId,
            'snapshot_id' => $snapshotId,
            'approval_id' => $approvalId,
            'target_set_hash' => $targetSetHash,
            'strategy' => $strategy->value,
            'timezone_id' => $timezoneId,
            'local_scheduled_at' => $localScheduledAt,
            'resolved_at_utc' => $resolvedAtUtc->format('Y-m-d\\TH:i:s.u\\Z'),
        ];
    }
}
