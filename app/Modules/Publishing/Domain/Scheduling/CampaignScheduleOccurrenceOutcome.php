<?php

namespace App\Modules\Publishing\Domain\Scheduling;

use App\Modules\Publishing\Domain\Campaign\CampaignApprovalEvaluation;
use App\Modules\Publishing\Domain\Campaign\CampaignApprovalInvalidReason;
use App\Modules\Publishing\Domain\Campaign\CampaignPayloadGuard;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class CampaignScheduleOccurrenceOutcome
{
    public function __construct(
        public string $id,
        public string $workspaceId,
        public string $campaignId,
        public string $snapshotId,
        public string $scheduleId,
        public string $scheduleHash,
        public string $scheduledApprovalId,
        public ?string $evaluatedDecisionId,
        public CampaignScheduleOccurrenceState $state,
        public CampaignScheduleMissedReason $missedReason,
        public ?CampaignApprovalInvalidReason $approvalInvalidReason,
        public ?string $approvalDetail,
        public DateTimeImmutable $resolvedAtUtc,
        public DateTimeImmutable $observedAt,
        public string $recordedByActorId,
        public string $idempotencyKey,
        public string $outcomeHash,
    ) {
        CampaignPayloadGuard::assertIdentifier($this->id, 'scheduleOutcome.id');
        CampaignPayloadGuard::assertIdentifier($this->workspaceId, 'scheduleOutcome.workspaceId');
        CampaignPayloadGuard::assertIdentifier($this->campaignId, 'scheduleOutcome.campaignId');
        CampaignPayloadGuard::assertIdentifier($this->snapshotId, 'scheduleOutcome.snapshotId');
        CampaignPayloadGuard::assertIdentifier($this->scheduleId, 'scheduleOutcome.scheduleId');
        CampaignPayloadGuard::assertIdentifier($this->scheduledApprovalId, 'scheduleOutcome.scheduledApprovalId');
        CampaignPayloadGuard::assertIdentifier($this->recordedByActorId, 'scheduleOutcome.recordedByActorId');
        CampaignPayloadGuard::assertIdentifier($this->idempotencyKey, 'scheduleOutcome.idempotencyKey');
        CampaignPayloadGuard::assertSha256($this->scheduleHash, 'scheduleOutcome.scheduleHash');
        CampaignPayloadGuard::assertSha256($this->outcomeHash, 'scheduleOutcome.outcomeHash');

        if ($this->evaluatedDecisionId !== null) {
            CampaignPayloadGuard::assertIdentifier($this->evaluatedDecisionId, 'scheduleOutcome.evaluatedDecisionId');
        }

        if ($this->resolvedAtUtc->getOffset() !== 0 || $this->observedAt->getOffset() !== 0) {
            throw new InvalidArgumentException('Campaign schedule outcome times must be normalized to UTC.');
        }

        if ($this->observedAt < $this->resolvedAtUtc) {
            throw new InvalidArgumentException('Campaign schedule outcome cannot be observed before the resolved occurrence is due.');
        }

        if (
            $this->missedReason === CampaignScheduleMissedReason::ApprovalInvalid
            && $this->approvalInvalidReason === null
        ) {
            throw new InvalidArgumentException('Approval-invalid schedule outcome requires an approval invalidation reason.');
        }

        if (
            $this->missedReason === CampaignScheduleMissedReason::ExecutionDeadlineMissed
            && $this->approvalInvalidReason !== null
        ) {
            throw new InvalidArgumentException('Execution-deadline schedule outcome cannot contain an approval invalidation reason.');
        }

        if ($this->approvalDetail !== null) {
            CampaignPayloadGuard::assertPublicJson($this->approvalDetail, 'scheduleOutcome.approvalDetail');

            if (mb_strlen($this->approvalDetail) > 1000) {
                throw new InvalidArgumentException('Campaign schedule outcome approval detail must not exceed 1000 characters.');
            }
        }

        $expectedHash = CampaignPayloadGuard::hash($this->canonicalPayload());
        if (! hash_equals($expectedHash, $this->outcomeHash)) {
            throw new InvalidArgumentException('Campaign schedule outcome hash does not match canonical evidence.');
        }
    }

    public static function approvalInvalid(
        string $id,
        CampaignSchedule $schedule,
        CampaignApprovalEvaluation $evaluation,
        string $recordedByActorId,
        string $idempotencyKey,
        DateTimeImmutable $observedAt,
    ): self {
        if ($evaluation->valid || $evaluation->reason === null) {
            throw new InvalidArgumentException('Approval-invalid schedule outcome requires an invalid approval evaluation.');
        }

        return self::create(
            id: $id,
            schedule: $schedule,
            evaluatedDecisionId: $evaluation->decisionId,
            missedReason: CampaignScheduleMissedReason::ApprovalInvalid,
            approvalInvalidReason: $evaluation->reason,
            approvalDetail: $evaluation->detail,
            recordedByActorId: $recordedByActorId,
            idempotencyKey: $idempotencyKey,
            observedAt: $observedAt,
        );
    }

    public static function executionDeadlineMissed(
        string $id,
        CampaignSchedule $schedule,
        string $evaluatedDecisionId,
        string $recordedByActorId,
        string $idempotencyKey,
        DateTimeImmutable $observedAt,
    ): self {
        if ($observedAt <= $schedule->resolvedAtUtc) {
            throw new InvalidArgumentException('Execution-deadline schedule outcome requires an observation after the resolved occurrence.');
        }

        return self::create(
            id: $id,
            schedule: $schedule,
            evaluatedDecisionId: $evaluatedDecisionId,
            missedReason: CampaignScheduleMissedReason::ExecutionDeadlineMissed,
            approvalInvalidReason: null,
            approvalDetail: null,
            recordedByActorId: $recordedByActorId,
            idempotencyKey: $idempotencyKey,
            observedAt: $observedAt,
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
            'evaluated_decision_id' => $this->evaluatedDecisionId,
            'outcome_state' => $this->state->value,
            'missed_reason' => $this->missedReason->value,
            'approval_invalid_reason' => $this->approvalInvalidReason?->value,
            'approval_detail' => $this->approvalDetail,
            'resolved_at_utc' => $this->resolvedAtUtc->format('Y-m-d\TH:i:s.u\Z'),
            'observed_at' => $this->observedAt->format('Y-m-d\TH:i:s.u\Z'),
            'recorded_by_actor_id' => $this->recordedByActorId,
        ];
    }

    private static function create(
        string $id,
        CampaignSchedule $schedule,
        ?string $evaluatedDecisionId,
        CampaignScheduleMissedReason $missedReason,
        ?CampaignApprovalInvalidReason $approvalInvalidReason,
        ?string $approvalDetail,
        string $recordedByActorId,
        string $idempotencyKey,
        DateTimeImmutable $observedAt,
    ): self {
        $payload = [
            'workspace_id' => $schedule->workspaceId,
            'campaign_id' => $schedule->campaignId,
            'snapshot_id' => $schedule->snapshotId,
            'schedule_id' => $schedule->id,
            'schedule_hash' => $schedule->scheduleHash,
            'scheduled_approval_id' => $schedule->approvalId,
            'evaluated_decision_id' => $evaluatedDecisionId,
            'outcome_state' => CampaignScheduleOccurrenceState::MissedNeedsReschedule->value,
            'missed_reason' => $missedReason->value,
            'approval_invalid_reason' => $approvalInvalidReason?->value,
            'approval_detail' => $approvalDetail,
            'resolved_at_utc' => $schedule->resolvedAtUtc->format('Y-m-d\TH:i:s.u\Z'),
            'observed_at' => $observedAt->format('Y-m-d\TH:i:s.u\Z'),
            'recorded_by_actor_id' => $recordedByActorId,
        ];

        return new self(
            id: $id,
            workspaceId: $schedule->workspaceId,
            campaignId: $schedule->campaignId,
            snapshotId: $schedule->snapshotId,
            scheduleId: $schedule->id,
            scheduleHash: $schedule->scheduleHash,
            scheduledApprovalId: $schedule->approvalId,
            evaluatedDecisionId: $evaluatedDecisionId,
            state: CampaignScheduleOccurrenceState::MissedNeedsReschedule,
            missedReason: $missedReason,
            approvalInvalidReason: $approvalInvalidReason,
            approvalDetail: $approvalDetail,
            resolvedAtUtc: $schedule->resolvedAtUtc,
            observedAt: $observedAt,
            recordedByActorId: $recordedByActorId,
            idempotencyKey: $idempotencyKey,
            outcomeHash: CampaignPayloadGuard::hash($payload),
        );
    }
}
