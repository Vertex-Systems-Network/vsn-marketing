<?php

namespace App\Modules\Publishing\Application\Scheduling;

use App\Modules\Identity\Application\Authorization\WorkspaceAuthorizer;
use App\Modules\Identity\Domain\Authorization\PermissionCatalog;
use App\Modules\Identity\Domain\Identity\User;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use App\Modules\Publishing\Application\Governance\CampaignApprovalEvaluator;
use App\Modules\Publishing\Domain\Campaign\CampaignApprovalEvaluation;
use App\Modules\Publishing\Domain\Campaign\CampaignSnapshot;
use App\Modules\Publishing\Domain\Campaign\CampaignStatus;
use App\Modules\Publishing\Domain\Scheduling\CampaignSchedule;
use App\Modules\Publishing\Domain\Scheduling\CampaignScheduleMutation;
use App\Modules\Publishing\Domain\Scheduling\CampaignScheduleMutationType;
use App\Modules\Publishing\Domain\Scheduling\CampaignScheduleOccurrenceOutcome;
use App\Modules\Publishing\Domain\Scheduling\CampaignScheduleRuleSet;
use App\Modules\Publishing\Domain\Scheduling\CampaignScheduleStrategy;
use App\Modules\Publishing\Domain\Scheduling\LocalScheduleTimeResolver;
use App\Modules\Publishing\Domain\Scheduling\QueueNextSlotResolver;
use App\Modules\Publishing\Infrastructure\Persistence\DatabaseCampaignRepository;
use App\Modules\Publishing\Infrastructure\Persistence\DatabaseCampaignScheduleMutationRepository;
use App\Modules\Publishing\Infrastructure\Persistence\DatabaseCampaignScheduleOutcomeRepository;
use App\Modules\Publishing\Infrastructure\Persistence\DatabaseCampaignScheduleRepository;
use App\Modules\Publishing\Infrastructure\Persistence\DatabaseCampaignScheduleRuleRepository;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;

final readonly class CampaignCalendarService
{
    public function __construct(
        private DatabaseCampaignRepository $campaigns,
        private DatabaseCampaignScheduleRepository $schedules,
        private DatabaseCampaignScheduleMutationRepository $mutations,
        private DatabaseCampaignScheduleOutcomeRepository $outcomes,
        private DatabaseCampaignScheduleRuleRepository $rules,
        private CampaignApprovalEvaluator $approvals,
        private LocalScheduleTimeResolver $resolver,
        private QueueNextSlotResolver $queueResolver,
        private WorkspaceAuthorizer $authorizer,
        private DatabaseManager $database,
    ) {}

    /**
     * @param  list<array{weekday: int, local_time: string}>  $slots
     */
    public function createQueueRuleSet(
        User $actor,
        TenantContext $context,
        string $ruleSetId,
        string $channel,
        string $timezoneId,
        array $slots,
        string $idempotencyKey,
        DateTimeImmutable $at,
    ): CampaignScheduleRuleSet {
        $this->assertScheduleAuthority($actor, $context);

        $existing = $this->rules->findByIdempotency($context->workspaceId, $idempotencyKey);
        if ($existing !== null) {
            $candidate = CampaignScheduleRuleSet::create(
                id: $ruleSetId,
                workspaceId: $context->workspaceId,
                parentRuleSetId: $existing->parentRuleSetId,
                channel: $channel,
                versionNumber: $existing->versionNumber,
                timezoneId: $timezoneId,
                slots: $slots,
                idempotencyKey: $idempotencyKey,
                createdByActorId: $context->actorId,
                createdAt: $existing->createdAt,
            );

            if (
                $existing->id !== $candidate->id
                || $existing->createdByActorId !== $candidate->createdByActorId
                || ! hash_equals($existing->ruleHash, $candidate->ruleHash)
            ) {
                throw new InvalidArgumentException(
                    'Campaign schedule rule replay conflicts with existing immutable rule state.',
                );
            }

            return $existing;
        }

        $latest = $this->rules->latest($context->workspaceId, $channel);
        $ruleSet = CampaignScheduleRuleSet::create(
            id: $ruleSetId,
            workspaceId: $context->workspaceId,
            parentRuleSetId: $latest?->id,
            channel: $channel,
            versionNumber: ($latest->versionNumber ?? 0) + 1,
            timezoneId: $timezoneId,
            slots: $slots,
            idempotencyKey: $idempotencyKey,
            createdByActorId: $context->actorId,
            createdAt: $at,
        );

        return $this->rules->create($ruleSet);
    }

    public function scheduleFixedInstant(
        User $actor,
        TenantContext $context,
        string $campaignId,
        string $snapshotId,
        string $scheduleId,
        string $idempotencyKey,
        DateTimeImmutable $at,
    ): CampaignSchedule {
        $this->assertScheduleAuthority($actor, $context);

        return $this->database->connection()->transaction(function () use (
            $context,
            $campaignId,
            $snapshotId,
            $scheduleId,
            $idempotencyKey,
            $at,
        ): CampaignSchedule {
            $campaign = $this->campaigns->lockCampaignForUpdate($context->workspaceId, $campaignId);
            $existing = $this->schedules->findByIdempotency($context->workspaceId, $idempotencyKey);

            if ($existing !== null) {
                $this->assertScheduleReplay(
                    $existing,
                    CampaignScheduleStrategy::FixedInstant,
                    $campaignId,
                    $snapshotId,
                    $scheduleId,
                    $context->actorId,
                );

                return $existing;
            }

            if ($campaign->status !== CampaignStatus::ScheduledIntent) {
                throw new InvalidArgumentException('Campaign calendar scheduling requires scheduled_intent lifecycle state.');
            }

            $snapshot = $this->requireSnapshot($context, $campaign->id, $snapshotId);
            $evaluation = $this->approvals->evaluate($campaign, $snapshot, $at);
            $this->assertEffectiveApproval($evaluation);

            $execution = $snapshot->intendedExecution;
            if (($execution['mode'] ?? null) !== CampaignScheduleStrategy::FixedInstant->value) {
                throw new InvalidArgumentException('Campaign calendar fixed-instant scheduling requires fixed_instant intended execution.');
            }

            $timezoneId = $execution['timezone'] ?? null;
            $localScheduledAt = $execution['at'] ?? null;

            if (! is_string($timezoneId) || ! is_string($localScheduledAt)) {
                throw new InvalidArgumentException('Campaign calendar fixed-instant scheduling requires timezone and local at values.');
            }

            $resolvedAtUtc = $this->resolver->resolve($timezoneId, $localScheduledAt);
            if ($resolvedAtUtc <= $at) {
                throw new InvalidArgumentException('Campaign calendar fixed-instant schedule must resolve to a future UTC instant.');
            }

            if ($evaluation->decisionId === null) {
                throw new InvalidArgumentException('Campaign calendar scheduling requires a concrete approval decision.');
            }

            return $this->schedules->create(CampaignSchedule::fixedInstant(
                id: $scheduleId,
                workspaceId: $context->workspaceId,
                campaignId: $campaign->id,
                snapshotId: $snapshot->id,
                approvalId: $evaluation->decisionId,
                targetSetHash: $snapshot->targetSetHash,
                timezoneId: $timezoneId,
                localScheduledAt: $localScheduledAt,
                resolvedAtUtc: $resolvedAtUtc,
                idempotencyKey: $idempotencyKey,
                createdByActorId: $context->actorId,
                createdAt: $at,
            ));
        });
    }

    public function scheduleQueueNextSlot(
        User $actor,
        TenantContext $context,
        string $campaignId,
        string $snapshotId,
        string $scheduleId,
        string $idempotencyKey,
        DateTimeImmutable $at,
    ): CampaignSchedule {
        $this->assertScheduleAuthority($actor, $context);

        return $this->database->connection()->transaction(function () use (
            $context,
            $campaignId,
            $snapshotId,
            $scheduleId,
            $idempotencyKey,
            $at,
        ): CampaignSchedule {
            $campaign = $this->campaigns->lockCampaignForUpdate($context->workspaceId, $campaignId);
            $existing = $this->schedules->findByIdempotency($context->workspaceId, $idempotencyKey);

            if ($existing !== null) {
                $this->assertScheduleReplay(
                    $existing,
                    CampaignScheduleStrategy::QueueNextSlot,
                    $campaignId,
                    $snapshotId,
                    $scheduleId,
                    $context->actorId,
                );

                return $existing;
            }

            if ($campaign->status !== CampaignStatus::ScheduledIntent) {
                throw new InvalidArgumentException('Campaign calendar scheduling requires scheduled_intent lifecycle state.');
            }

            $snapshot = $this->requireSnapshot($context, $campaign->id, $snapshotId);
            $evaluation = $this->approvals->evaluate($campaign, $snapshot, $at);
            $this->assertEffectiveApproval($evaluation);

            $execution = $snapshot->intendedExecution;
            if (($execution['mode'] ?? null) !== CampaignScheduleStrategy::QueueNextSlot->value) {
                throw new InvalidArgumentException('Campaign queue scheduling requires queue_next_slot intended execution.');
            }

            $ruleSetId = $execution['rule_set_id'] ?? null;
            $channel = $execution['channel'] ?? null;

            if (
                ! is_string($ruleSetId)
                || trim($ruleSetId) === ''
                || ! is_string($channel)
                || trim($channel) === ''
            ) {
                throw new InvalidArgumentException('Campaign queue scheduling must pin rule_set_id and channel.');
            }

            $this->assertSnapshotChannel($snapshot, $channel);

            $ruleSet = $this->rules->find($context->workspaceId, $ruleSetId);
            if (! $ruleSet instanceof CampaignScheduleRuleSet || $ruleSet->channel !== $channel) {
                throw new InvalidArgumentException('Campaign queue scheduling rule set does not match the intended channel.');
            }

            $resolved = $this->queueResolver->resolve($ruleSet, $at);

            if ($evaluation->decisionId === null) {
                throw new InvalidArgumentException('Campaign calendar scheduling requires a concrete approval decision.');
            }

            return $this->schedules->create(CampaignSchedule::queueNextSlot(
                id: $scheduleId,
                workspaceId: $context->workspaceId,
                campaignId: $campaign->id,
                snapshotId: $snapshot->id,
                approvalId: $evaluation->decisionId,
                targetSetHash: $snapshot->targetSetHash,
                channel: $channel,
                ruleSet: $ruleSet,
                localScheduledAt: $resolved->localScheduledAt,
                resolvedAtUtc: $resolved->resolvedAtUtc,
                idempotencyKey: $idempotencyKey,
                createdByActorId: $context->actorId,
                createdAt: $at,
            ));
        });
    }

    public function rescheduleFixedInstant(
        User $actor,
        TenantContext $context,
        string $previousScheduleId,
        string $replacementSnapshotId,
        string $replacementScheduleId,
        string $replacementScheduleIdempotencyKey,
        string $mutationId,
        string $mutationIdempotencyKey,
        string $reason,
        DateTimeImmutable $at,
    ): CampaignScheduleMutation {
        $this->assertScheduleAuthority($actor, $context);

        return $this->database->connection()->transaction(function () use (
            $actor,
            $context,
            $previousScheduleId,
            $replacementSnapshotId,
            $replacementScheduleId,
            $replacementScheduleIdempotencyKey,
            $mutationId,
            $mutationIdempotencyKey,
            $reason,
            $at,
        ): CampaignScheduleMutation {
            $existing = $this->mutations->findByIdempotency(
                $context->workspaceId,
                $mutationIdempotencyKey,
            );

            if ($existing !== null) {
                $this->assertMutationReplay(
                    existing: $existing,
                    type: CampaignScheduleMutationType::Rescheduled,
                    mutationId: $mutationId,
                    previousScheduleId: $previousScheduleId,
                    replacementScheduleId: $replacementScheduleId,
                    actorId: $context->actorId,
                    reason: $reason,
                );

                return $existing;
            }

            $previous = $this->requireSchedule($context, $previousScheduleId);
            if ($previous->strategy !== CampaignScheduleStrategy::FixedInstant) {
                throw new InvalidArgumentException(
                    'Campaign fixed-instant reschedule requires a fixed-instant source schedule.',
                );
            }
            $this->assertScheduleMutationWindow($previous, $at);

            $replacement = $this->scheduleFixedInstant(
                actor: $actor,
                context: $context,
                campaignId: $previous->campaignId,
                snapshotId: $replacementSnapshotId,
                scheduleId: $replacementScheduleId,
                idempotencyKey: $replacementScheduleIdempotencyKey,
                at: $at,
            );

            return $this->mutations->create(CampaignScheduleMutation::rescheduled(
                id: $mutationId,
                previous: $previous,
                replacement: $replacement,
                actorId: $context->actorId,
                reason: $reason,
                idempotencyKey: $mutationIdempotencyKey,
                occurredAt: $at,
            ));
        });
    }

    public function rescheduleQueueNextSlot(
        User $actor,
        TenantContext $context,
        string $previousScheduleId,
        string $replacementSnapshotId,
        string $replacementScheduleId,
        string $replacementScheduleIdempotencyKey,
        string $mutationId,
        string $mutationIdempotencyKey,
        string $reason,
        DateTimeImmutable $at,
    ): CampaignScheduleMutation {
        $this->assertScheduleAuthority($actor, $context);

        return $this->database->connection()->transaction(function () use (
            $actor,
            $context,
            $previousScheduleId,
            $replacementSnapshotId,
            $replacementScheduleId,
            $replacementScheduleIdempotencyKey,
            $mutationId,
            $mutationIdempotencyKey,
            $reason,
            $at,
        ): CampaignScheduleMutation {
            $existing = $this->mutations->findByIdempotency(
                $context->workspaceId,
                $mutationIdempotencyKey,
            );

            if ($existing !== null) {
                $this->assertMutationReplay(
                    existing: $existing,
                    type: CampaignScheduleMutationType::Rescheduled,
                    mutationId: $mutationId,
                    previousScheduleId: $previousScheduleId,
                    replacementScheduleId: $replacementScheduleId,
                    actorId: $context->actorId,
                    reason: $reason,
                );

                return $existing;
            }

            $previous = $this->requireSchedule($context, $previousScheduleId);
            if ($previous->strategy !== CampaignScheduleStrategy::QueueNextSlot) {
                throw new InvalidArgumentException(
                    'Campaign queue reschedule requires a queue_next_slot source schedule.',
                );
            }
            $this->assertScheduleMutationWindow($previous, $at);

            $replacement = $this->scheduleQueueNextSlot(
                actor: $actor,
                context: $context,
                campaignId: $previous->campaignId,
                snapshotId: $replacementSnapshotId,
                scheduleId: $replacementScheduleId,
                idempotencyKey: $replacementScheduleIdempotencyKey,
                at: $at,
            );

            return $this->mutations->create(CampaignScheduleMutation::rescheduled(
                id: $mutationId,
                previous: $previous,
                replacement: $replacement,
                actorId: $context->actorId,
                reason: $reason,
                idempotencyKey: $mutationIdempotencyKey,
                occurredAt: $at,
            ));
        });
    }

    public function cancelSchedule(
        User $actor,
        TenantContext $context,
        string $scheduleId,
        string $mutationId,
        string $mutationIdempotencyKey,
        string $reason,
        DateTimeImmutable $at,
    ): CampaignScheduleMutation {
        $this->assertScheduleAuthority($actor, $context);

        return $this->database->connection()->transaction(function () use (
            $context,
            $scheduleId,
            $mutationId,
            $mutationIdempotencyKey,
            $reason,
            $at,
        ): CampaignScheduleMutation {
            $existing = $this->mutations->findByIdempotency(
                $context->workspaceId,
                $mutationIdempotencyKey,
            );

            if ($existing !== null) {
                $this->assertMutationReplay(
                    existing: $existing,
                    type: CampaignScheduleMutationType::Cancelled,
                    mutationId: $mutationId,
                    previousScheduleId: $scheduleId,
                    replacementScheduleId: null,
                    actorId: $context->actorId,
                    reason: $reason,
                );

                return $existing;
            }

            $previous = $this->requireSchedule($context, $scheduleId);
            $this->assertScheduleMutationWindow($previous, $at);

            return $this->mutations->create(CampaignScheduleMutation::cancelled(
                id: $mutationId,
                previous: $previous,
                actorId: $context->actorId,
                reason: $reason,
                idempotencyKey: $mutationIdempotencyKey,
                occurredAt: $at,
            ));
        });
    }

    public function recordMissedOccurrence(
        User $actor,
        TenantContext $context,
        string $scheduleId,
        string $outcomeId,
        string $idempotencyKey,
        DateTimeImmutable $at,
    ): CampaignScheduleOccurrenceOutcome {
        $this->assertScheduleAuthority($actor, $context);

        return $this->database->connection()->transaction(function () use (
            $context,
            $scheduleId,
            $outcomeId,
            $idempotencyKey,
            $at,
        ): CampaignScheduleOccurrenceOutcome {
            $existing = $this->outcomes->findByIdempotency(
                $context->workspaceId,
                $idempotencyKey,
            );

            if ($existing !== null) {
                $this->assertOutcomeReplay(
                    existing: $existing,
                    outcomeId: $outcomeId,
                    scheduleId: $scheduleId,
                    actorId: $context->actorId,
                );

                return $existing;
            }

            $schedule = $this->requireSchedule($context, $scheduleId);
            $observedAt = $at->setTimezone(new DateTimeZone('UTC'));

            if ($observedAt < $schedule->resolvedAtUtc) {
                throw new InvalidArgumentException(
                    'Campaign schedule missed outcome cannot be recorded before the resolved occurrence is due.',
                );
            }

            $campaign = $this->campaigns->lockCampaignForUpdate(
                $context->workspaceId,
                $schedule->campaignId,
            );

            if ($campaign->status->isTerminal()) {
                throw new InvalidArgumentException(
                    'Campaign schedule missed outcome cannot be recorded for a terminal campaign.',
                );
            }

            $snapshot = $this->requireSnapshot(
                $context,
                $campaign->id,
                $schedule->snapshotId,
            );
            $evaluation = $this->approvals->evaluate($campaign, $snapshot, $observedAt);

            if (! $evaluation->valid) {
                return $this->outcomes->create(CampaignScheduleOccurrenceOutcome::approvalInvalid(
                    id: $outcomeId,
                    schedule: $schedule,
                    evaluation: $evaluation,
                    recordedByActorId: $context->actorId,
                    idempotencyKey: $idempotencyKey,
                    observedAt: $observedAt,
                ));
            }

            if ($observedAt->format('U.u') === $schedule->resolvedAtUtc->format('U.u')) {
                throw new InvalidArgumentException(
                    'Campaign schedule remains approval-eligible at the exact due boundary; AC-6 owns execution claiming.',
                );
            }

            if ($evaluation->decisionId === null) {
                throw new InvalidArgumentException(
                    'Campaign schedule due-boundary evaluation requires a concrete approval decision.',
                );
            }

            return $this->outcomes->create(CampaignScheduleOccurrenceOutcome::executionDeadlineMissed(
                id: $outcomeId,
                schedule: $schedule,
                evaluatedDecisionId: $evaluation->decisionId,
                recordedByActorId: $context->actorId,
                idempotencyKey: $idempotencyKey,
                observedAt: $observedAt,
            ));
        });
    }

    private function assertOutcomeReplay(
        CampaignScheduleOccurrenceOutcome $existing,
        string $outcomeId,
        string $scheduleId,
        string $actorId,
    ): void {
        if (
            $existing->id !== $outcomeId
            || $existing->scheduleId !== $scheduleId
            || $existing->recordedByActorId !== $actorId
        ) {
            throw new InvalidArgumentException(
                'Campaign schedule missed-outcome replay conflicts with immutable history.',
            );
        }
    }

    private function requireSchedule(
        TenantContext $context,
        string $scheduleId,
    ): CampaignSchedule {
        $schedule = $this->schedules->find($context->workspaceId, $scheduleId);

        if (! $schedule instanceof CampaignSchedule) {
            throw new InvalidArgumentException(
                'Campaign schedule does not exist in this workspace.',
            );
        }

        return $schedule;
    }

    private function assertScheduleMutationWindow(
        CampaignSchedule $schedule,
        DateTimeImmutable $at,
    ): void {
        if ($schedule->resolvedAtUtc <= $at) {
            throw new InvalidArgumentException(
                'Campaign schedule cannot be rescheduled or cancelled after its resolved occurrence is due.',
            );
        }
    }

    private function assertMutationReplay(
        CampaignScheduleMutation $existing,
        CampaignScheduleMutationType $type,
        string $mutationId,
        string $previousScheduleId,
        ?string $replacementScheduleId,
        string $actorId,
        string $reason,
    ): void {
        if (
            $existing->type !== $type
            || $existing->id !== $mutationId
            || $existing->previousScheduleId !== $previousScheduleId
            || $existing->replacementScheduleId !== $replacementScheduleId
            || $existing->actorId !== $actorId
            || $existing->reason !== $reason
        ) {
            throw new InvalidArgumentException(
                'Campaign schedule mutation replay conflicts with immutable history.',
            );
        }
    }

    private function requireSnapshot(
        TenantContext $context,
        string $campaignId,
        string $snapshotId,
    ): CampaignSnapshot {
        $snapshot = $this->campaigns->findSnapshot($context->workspaceId, $snapshotId);

        if (! $snapshot instanceof CampaignSnapshot || $snapshot->campaignId !== $campaignId) {
            throw new InvalidArgumentException('Campaign calendar scheduling requires the exact immutable campaign snapshot.');
        }

        return $snapshot;
    }

    private function assertSnapshotChannel(CampaignSnapshot $snapshot, string $channel): void
    {
        foreach ($snapshot->targets as $target) {
            if ($target->channel === $channel) {
                return;
            }
        }

        throw new InvalidArgumentException(
            'Campaign queue scheduling channel is not present in the immutable snapshot target set.',
        );
    }

    private function assertScheduleAuthority(User $actor, TenantContext $context): void
    {
        if (! $this->authorizer->allows($actor, $context, PermissionCatalog::CAMPAIGN_SEND)) {
            throw new AuthorizationException('Campaign scheduling requires campaign.send permission.');
        }
    }

    private function assertScheduleReplay(
        CampaignSchedule $existing,
        CampaignScheduleStrategy $strategy,
        string $campaignId,
        string $snapshotId,
        string $scheduleId,
        string $actorId,
    ): void {
        if (
            $existing->strategy !== $strategy
            || $existing->id !== $scheduleId
            || $existing->campaignId !== $campaignId
            || $existing->snapshotId !== $snapshotId
            || $existing->createdByActorId !== $actorId
        ) {
            throw new InvalidArgumentException(
                'Campaign calendar schedule replay conflicts with existing immutable schedule state.',
            );
        }
    }

    private function assertEffectiveApproval(CampaignApprovalEvaluation $evaluation): void
    {
        if ($evaluation->valid) {
            return;
        }

        $reason = $evaluation->reason->value;

        throw new InvalidArgumentException(
            'Campaign calendar scheduling requires effective approval: '.$reason.'.',
        );
    }
}
