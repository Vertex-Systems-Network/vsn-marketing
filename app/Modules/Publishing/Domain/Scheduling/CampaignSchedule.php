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
        public ?string $channel = null,
        public ?string $ruleSetId = null,
        public ?int $ruleVersion = null,
        public ?string $ruleHash = null,
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

        if ($this->resolvedAtUtc->getOffset() !== 0) {
            throw new InvalidArgumentException('Campaign schedule resolvedAtUtc must be normalized to UTC.');
        }

        if ($this->strategy === CampaignScheduleStrategy::FixedInstant) {
            if (
                $this->channel !== null
                || $this->ruleSetId !== null
                || $this->ruleVersion !== null
                || $this->ruleHash !== null
            ) {
                throw new InvalidArgumentException('Fixed-instant campaign schedule cannot contain queue-rule metadata.');
            }
        } else {
            if (
                $this->channel === null
                || $this->ruleSetId === null
                || $this->ruleVersion === null
                || $this->ruleHash === null
            ) {
                throw new InvalidArgumentException('Queue campaign schedule must pin channel and immutable rule-set metadata.');
            }

            CampaignPayloadGuard::assertIdentifier($this->channel, 'schedule.channel');
            CampaignPayloadGuard::assertIdentifier($this->ruleSetId, 'schedule.ruleSetId');
            CampaignPayloadGuard::assertSha256($this->ruleHash, 'schedule.ruleHash');

            if ($this->ruleVersion < 1) {
                throw new InvalidArgumentException('Queue campaign schedule rule version must be at least 1.');
            }
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
        $payload = self::fixedPayload(
            workspaceId: $workspaceId,
            campaignId: $campaignId,
            snapshotId: $snapshotId,
            approvalId: $approvalId,
            targetSetHash: $targetSetHash,
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

    public static function queueNextSlot(
        string $id,
        string $workspaceId,
        string $campaignId,
        string $snapshotId,
        string $approvalId,
        string $targetSetHash,
        string $channel,
        CampaignScheduleRuleSet $ruleSet,
        string $localScheduledAt,
        DateTimeImmutable $resolvedAtUtc,
        string $idempotencyKey,
        string $createdByActorId,
        DateTimeImmutable $createdAt,
    ): self {
        if ($ruleSet->workspaceId !== $workspaceId || $ruleSet->channel !== $channel) {
            throw new InvalidArgumentException('Queue campaign schedule rule set does not match workspace/channel authority.');
        }

        $payload = self::queuePayload(
            workspaceId: $workspaceId,
            campaignId: $campaignId,
            snapshotId: $snapshotId,
            approvalId: $approvalId,
            targetSetHash: $targetSetHash,
            timezoneId: $ruleSet->timezoneId,
            localScheduledAt: $localScheduledAt,
            resolvedAtUtc: $resolvedAtUtc,
            channel: $channel,
            ruleSetId: $ruleSet->id,
            ruleVersion: $ruleSet->versionNumber,
            ruleHash: $ruleSet->ruleHash,
        );

        return new self(
            id: $id,
            workspaceId: $workspaceId,
            campaignId: $campaignId,
            snapshotId: $snapshotId,
            approvalId: $approvalId,
            targetSetHash: $targetSetHash,
            strategy: CampaignScheduleStrategy::QueueNextSlot,
            timezoneId: $ruleSet->timezoneId,
            localScheduledAt: $localScheduledAt,
            resolvedAtUtc: $resolvedAtUtc,
            scheduleHash: CampaignPayloadGuard::hash($payload),
            idempotencyKey: $idempotencyKey,
            createdByActorId: $createdByActorId,
            createdAt: $createdAt,
            channel: $channel,
            ruleSetId: $ruleSet->id,
            ruleVersion: $ruleSet->versionNumber,
            ruleHash: $ruleSet->ruleHash,
        );
    }

    /** @return array<string, mixed> */
    public function canonicalPayload(): array
    {
        if ($this->strategy === CampaignScheduleStrategy::FixedInstant) {
            return self::fixedPayload(
                workspaceId: $this->workspaceId,
                campaignId: $this->campaignId,
                snapshotId: $this->snapshotId,
                approvalId: $this->approvalId,
                targetSetHash: $this->targetSetHash,
                timezoneId: $this->timezoneId,
                localScheduledAt: $this->localScheduledAt,
                resolvedAtUtc: $this->resolvedAtUtc,
            );
        }

        return self::queuePayload(
            workspaceId: $this->workspaceId,
            campaignId: $this->campaignId,
            snapshotId: $this->snapshotId,
            approvalId: $this->approvalId,
            targetSetHash: $this->targetSetHash,
            timezoneId: $this->timezoneId,
            localScheduledAt: $this->localScheduledAt,
            resolvedAtUtc: $this->resolvedAtUtc,
            channel: (string) $this->channel,
            ruleSetId: (string) $this->ruleSetId,
            ruleVersion: (int) $this->ruleVersion,
            ruleHash: (string) $this->ruleHash,
        );
    }

    /** @return array<string, mixed> */
    private static function fixedPayload(
        string $workspaceId,
        string $campaignId,
        string $snapshotId,
        string $approvalId,
        string $targetSetHash,
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
            'strategy' => CampaignScheduleStrategy::FixedInstant->value,
            'timezone_id' => $timezoneId,
            'local_scheduled_at' => $localScheduledAt,
            'resolved_at_utc' => $resolvedAtUtc->format('Y-m-d\TH:i:s.u\Z'),
        ];
    }

    /** @return array<string, mixed> */
    private static function queuePayload(
        string $workspaceId,
        string $campaignId,
        string $snapshotId,
        string $approvalId,
        string $targetSetHash,
        string $timezoneId,
        string $localScheduledAt,
        DateTimeImmutable $resolvedAtUtc,
        string $channel,
        string $ruleSetId,
        int $ruleVersion,
        string $ruleHash,
    ): array {
        return [
            'workspace_id' => $workspaceId,
            'campaign_id' => $campaignId,
            'snapshot_id' => $snapshotId,
            'approval_id' => $approvalId,
            'target_set_hash' => $targetSetHash,
            'strategy' => CampaignScheduleStrategy::QueueNextSlot->value,
            'timezone_id' => $timezoneId,
            'local_scheduled_at' => $localScheduledAt,
            'resolved_at_utc' => $resolvedAtUtc->format('Y-m-d\TH:i:s.u\Z'),
            'channel' => $channel,
            'rule_set_id' => $ruleSetId,
            'rule_version' => $ruleVersion,
            'rule_hash' => $ruleHash,
        ];
    }
}
