<?php

namespace App\Modules\Publishing\Domain\Scheduling;

use App\Modules\Publishing\Domain\Campaign\CampaignPayloadGuard;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class CampaignScheduleMutation
{
    public function __construct(
        public string $id,
        public string $workspaceId,
        public string $campaignId,
        public CampaignScheduleMutationType $type,
        public string $previousScheduleId,
        public string $previousScheduleHash,
        public DateTimeImmutable $previousResolvedAtUtc,
        public ?string $replacementScheduleId,
        public ?string $replacementScheduleHash,
        public ?DateTimeImmutable $replacementResolvedAtUtc,
        public string $actorId,
        public string $reason,
        public string $idempotencyKey,
        public DateTimeImmutable $occurredAt,
        public string $mutationHash,
    ) {
        CampaignPayloadGuard::assertIdentifier($this->id, 'scheduleMutation.id');
        CampaignPayloadGuard::assertIdentifier($this->workspaceId, 'scheduleMutation.workspaceId');
        CampaignPayloadGuard::assertIdentifier($this->campaignId, 'scheduleMutation.campaignId');
        CampaignPayloadGuard::assertIdentifier($this->previousScheduleId, 'scheduleMutation.previousScheduleId');
        CampaignPayloadGuard::assertIdentifier($this->actorId, 'scheduleMutation.actorId');
        CampaignPayloadGuard::assertIdentifier($this->idempotencyKey, 'scheduleMutation.idempotencyKey');
        CampaignPayloadGuard::assertSha256($this->previousScheduleHash, 'scheduleMutation.previousScheduleHash');
        CampaignPayloadGuard::assertSha256($this->mutationHash, 'scheduleMutation.mutationHash');

        if (trim($this->reason) === '' || mb_strlen($this->reason) > 1000) {
            throw new InvalidArgumentException('Campaign schedule mutation reason must contain 1-1000 characters.');
        }
        CampaignPayloadGuard::assertPublicJson($this->reason, 'scheduleMutation.reason');

        if ($this->previousResolvedAtUtc->getOffset() !== 0) {
            throw new InvalidArgumentException('Campaign schedule mutation previous resolved instant must be UTC.');
        }

        if ($this->type === CampaignScheduleMutationType::Rescheduled) {
            if (
                $this->replacementScheduleId === null
                || $this->replacementScheduleHash === null
                || $this->replacementResolvedAtUtc === null
            ) {
                throw new InvalidArgumentException('Campaign reschedule mutation requires replacement schedule evidence.');
            }

            CampaignPayloadGuard::assertIdentifier($this->replacementScheduleId, 'scheduleMutation.replacementScheduleId');
            CampaignPayloadGuard::assertSha256($this->replacementScheduleHash, 'scheduleMutation.replacementScheduleHash');

            if ($this->replacementScheduleId === $this->previousScheduleId) {
                throw new InvalidArgumentException('Campaign reschedule mutation must use a distinct replacement schedule.');
            }

            if ($this->replacementResolvedAtUtc->getOffset() !== 0) {
                throw new InvalidArgumentException('Campaign reschedule replacement resolved instant must be UTC.');
            }

            if (hash_equals($this->previousScheduleHash, $this->replacementScheduleHash)) {
                throw new InvalidArgumentException('Campaign reschedule mutation must materially change canonical schedule evidence.');
            }

            if (
                $this->replacementResolvedAtUtc->format('U.u')
                === $this->previousResolvedAtUtc->format('U.u')
            ) {
                throw new InvalidArgumentException('Campaign reschedule mutation must change the resolved UTC instant.');
            }

            if ($this->replacementResolvedAtUtc <= $this->occurredAt) {
                throw new InvalidArgumentException('Campaign reschedule replacement must resolve to a future UTC instant.');
            }
        } elseif (
            $this->replacementScheduleId !== null
            || $this->replacementScheduleHash !== null
            || $this->replacementResolvedAtUtc !== null
        ) {
            throw new InvalidArgumentException('Campaign cancellation mutation cannot contain replacement schedule evidence.');
        }

        if ($this->previousResolvedAtUtc <= $this->occurredAt) {
            throw new InvalidArgumentException('Campaign schedule mutation cannot rewrite a due or past occurrence.');
        }

        $expectedHash = CampaignPayloadGuard::hash($this->canonicalPayload());
        if (! hash_equals($expectedHash, $this->mutationHash)) {
            throw new InvalidArgumentException('Campaign schedule mutation hash does not match canonical evidence.');
        }
    }

    public static function rescheduled(
        string $id,
        CampaignSchedule $previous,
        CampaignSchedule $replacement,
        string $actorId,
        string $reason,
        string $idempotencyKey,
        DateTimeImmutable $occurredAt,
    ): self {
        if (
            $previous->workspaceId !== $replacement->workspaceId
            || $previous->campaignId !== $replacement->campaignId
        ) {
            throw new InvalidArgumentException('Campaign reschedule schedules must share workspace and campaign authority.');
        }

        if ($replacement->createdAt->format('U.u') !== $occurredAt->format('U.u')) {
            throw new InvalidArgumentException('Campaign replacement schedule creation time must match mutation time.');
        }

        $payload = self::payload(
            workspaceId: $previous->workspaceId,
            campaignId: $previous->campaignId,
            type: CampaignScheduleMutationType::Rescheduled,
            previousScheduleId: $previous->id,
            previousScheduleHash: $previous->scheduleHash,
            previousResolvedAtUtc: $previous->resolvedAtUtc,
            replacementScheduleId: $replacement->id,
            replacementScheduleHash: $replacement->scheduleHash,
            replacementResolvedAtUtc: $replacement->resolvedAtUtc,
            actorId: $actorId,
            reason: $reason,
            occurredAt: $occurredAt,
        );

        return new self(
            id: $id,
            workspaceId: $previous->workspaceId,
            campaignId: $previous->campaignId,
            type: CampaignScheduleMutationType::Rescheduled,
            previousScheduleId: $previous->id,
            previousScheduleHash: $previous->scheduleHash,
            previousResolvedAtUtc: $previous->resolvedAtUtc,
            replacementScheduleId: $replacement->id,
            replacementScheduleHash: $replacement->scheduleHash,
            replacementResolvedAtUtc: $replacement->resolvedAtUtc,
            actorId: $actorId,
            reason: $reason,
            idempotencyKey: $idempotencyKey,
            occurredAt: $occurredAt,
            mutationHash: CampaignPayloadGuard::hash($payload),
        );
    }

    public static function cancelled(
        string $id,
        CampaignSchedule $previous,
        string $actorId,
        string $reason,
        string $idempotencyKey,
        DateTimeImmutable $occurredAt,
    ): self {
        $payload = self::payload(
            workspaceId: $previous->workspaceId,
            campaignId: $previous->campaignId,
            type: CampaignScheduleMutationType::Cancelled,
            previousScheduleId: $previous->id,
            previousScheduleHash: $previous->scheduleHash,
            previousResolvedAtUtc: $previous->resolvedAtUtc,
            replacementScheduleId: null,
            replacementScheduleHash: null,
            replacementResolvedAtUtc: null,
            actorId: $actorId,
            reason: $reason,
            occurredAt: $occurredAt,
        );

        return new self(
            id: $id,
            workspaceId: $previous->workspaceId,
            campaignId: $previous->campaignId,
            type: CampaignScheduleMutationType::Cancelled,
            previousScheduleId: $previous->id,
            previousScheduleHash: $previous->scheduleHash,
            previousResolvedAtUtc: $previous->resolvedAtUtc,
            replacementScheduleId: null,
            replacementScheduleHash: null,
            replacementResolvedAtUtc: null,
            actorId: $actorId,
            reason: $reason,
            idempotencyKey: $idempotencyKey,
            occurredAt: $occurredAt,
            mutationHash: CampaignPayloadGuard::hash($payload),
        );
    }

    /** @return array<string, mixed> */
    public function canonicalPayload(): array
    {
        return self::payload(
            workspaceId: $this->workspaceId,
            campaignId: $this->campaignId,
            type: $this->type,
            previousScheduleId: $this->previousScheduleId,
            previousScheduleHash: $this->previousScheduleHash,
            previousResolvedAtUtc: $this->previousResolvedAtUtc,
            replacementScheduleId: $this->replacementScheduleId,
            replacementScheduleHash: $this->replacementScheduleHash,
            replacementResolvedAtUtc: $this->replacementResolvedAtUtc,
            actorId: $this->actorId,
            reason: $this->reason,
            occurredAt: $this->occurredAt,
        );
    }

    /** @return array<string, mixed> */
    private static function payload(
        string $workspaceId,
        string $campaignId,
        CampaignScheduleMutationType $type,
        string $previousScheduleId,
        string $previousScheduleHash,
        DateTimeImmutable $previousResolvedAtUtc,
        ?string $replacementScheduleId,
        ?string $replacementScheduleHash,
        ?DateTimeImmutable $replacementResolvedAtUtc,
        string $actorId,
        string $reason,
        DateTimeImmutable $occurredAt,
    ): array {
        $utc = new DateTimeZone('UTC');

        return [
            'workspace_id' => $workspaceId,
            'campaign_id' => $campaignId,
            'type' => $type->value,
            'previous_schedule_id' => $previousScheduleId,
            'previous_schedule_hash' => $previousScheduleHash,
            'previous_resolved_at_utc' => $previousResolvedAtUtc->setTimezone($utc)->format('Y-m-d\TH:i:s.u\Z'),
            'replacement_schedule_id' => $replacementScheduleId,
            'replacement_schedule_hash' => $replacementScheduleHash,
            'replacement_resolved_at_utc' => $replacementResolvedAtUtc?->setTimezone($utc)->format('Y-m-d\TH:i:s.u\Z'),
            'actor_id' => $actorId,
            'reason' => $reason,
            'occurred_at' => $occurredAt->setTimezone($utc)->format('Y-m-d\TH:i:s.u\Z'),
        ];
    }
}
