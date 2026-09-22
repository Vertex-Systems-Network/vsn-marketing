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
use App\Modules\Publishing\Domain\Scheduling\CampaignScheduleRuleSet;
use App\Modules\Publishing\Domain\Scheduling\CampaignScheduleStrategy;
use App\Modules\Publishing\Domain\Scheduling\LocalScheduleTimeResolver;
use App\Modules\Publishing\Domain\Scheduling\QueueNextSlotResolver;
use App\Modules\Publishing\Infrastructure\Persistence\DatabaseCampaignRepository;
use App\Modules\Publishing\Infrastructure\Persistence\DatabaseCampaignScheduleRepository;
use App\Modules\Publishing\Infrastructure\Persistence\DatabaseCampaignScheduleRuleRepository;
use DateTimeImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;

final readonly class CampaignCalendarService
{
    public function __construct(
        private DatabaseCampaignRepository $campaigns,
        private DatabaseCampaignScheduleRepository $schedules,
        private DatabaseCampaignScheduleRuleRepository $rules,
        private CampaignApprovalEvaluator $approvals,
        private LocalScheduleTimeResolver $resolver,
        private QueueNextSlotResolver $queueResolver,
        private WorkspaceAuthorizer $authorizer,
        private DatabaseManager $database,
    ) {}

    /**
     * @param list<array{weekday: int, local_time: string}> $slots
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
