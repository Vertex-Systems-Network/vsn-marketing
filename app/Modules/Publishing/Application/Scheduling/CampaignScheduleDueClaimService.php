<?php

namespace App\Modules\Publishing\Application\Scheduling;

use App\Modules\Core\Domain\Contracts\IdentifierGenerator;
use App\Modules\Core\Domain\Contracts\OutboxRepository;
use App\Modules\Core\Domain\Messaging\OutboxMessage;
use App\Modules\Publishing\Application\Governance\CampaignApprovalEvaluator;
use App\Modules\Publishing\Domain\Campaign\CampaignPayloadGuard;
use App\Modules\Publishing\Domain\Campaign\CampaignStatus;
use App\Modules\Publishing\Domain\Scheduling\CampaignScheduleDueClaim;
use App\Modules\Publishing\Domain\Scheduling\CampaignScheduleDueClaimState;
use App\Modules\Publishing\Domain\Scheduling\CampaignScheduleExecutionIntent;
use App\Modules\Publishing\Domain\Scheduling\CampaignScheduleOccurrenceOutcome;
use App\Modules\Publishing\Infrastructure\Persistence\DatabaseCampaignRepository;
use App\Modules\Publishing\Infrastructure\Persistence\DatabaseCampaignScheduleExecutionRepository;
use App\Modules\Publishing\Infrastructure\Persistence\DatabaseCampaignScheduleOutcomeRepository;
use App\Modules\Publishing\Infrastructure\Persistence\DatabaseCampaignScheduleRepository;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;

final readonly class CampaignScheduleDueClaimService
{
    private const OUTBOX_TOPIC = 'publishing.campaign_schedule.execution_intent.ready';

    public function __construct(
        private DatabaseManager $database,
        private IdentifierGenerator $identifiers,
        private OutboxRepository $outbox,
        private DatabaseCampaignRepository $campaigns,
        private DatabaseCampaignScheduleRepository $schedules,
        private DatabaseCampaignScheduleOutcomeRepository $outcomes,
        private DatabaseCampaignScheduleExecutionRepository $executions,
        private CampaignApprovalEvaluator $approvals,
    ) {}

    public function acquireDueClaim(
        string $workspaceId,
        string $scheduleId,
        string $leaseOwner,
        string $leaseToken,
        int $leaseSeconds,
        DateTimeImmutable $at,
    ): ?CampaignScheduleDueClaim {
        CampaignPayloadGuard::assertIdentifier($workspaceId, 'dueClaim.workspaceId');
        CampaignPayloadGuard::assertIdentifier($scheduleId, 'dueClaim.scheduleId');
        CampaignPayloadGuard::assertIdentifier($leaseOwner, 'dueClaim.leaseOwner');
        CampaignPayloadGuard::assertIdentifier($leaseToken, 'dueClaim.leaseToken');

        if ($leaseSeconds < 1 || $leaseSeconds > 3600) {
            throw new InvalidArgumentException('Campaign schedule due claim lease must be between 1 and 3600 seconds.');
        }

        $observedAt = $at->setTimezone(new DateTimeZone('UTC'));

        return $this->database->connection()->transaction(function () use (
            $workspaceId,
            $scheduleId,
            $leaseOwner,
            $leaseToken,
            $leaseSeconds,
            $observedAt,
        ): ?CampaignScheduleDueClaim {
            $schedule = $this->schedules->lockForUpdate($workspaceId, $scheduleId);
            if ($schedule === null) {
                throw new InvalidArgumentException('Campaign schedule due claim source does not exist in this workspace.');
            }

            $existingIntent = $this->executions->findIntentBySchedule($workspaceId, $scheduleId, true);
            $existingClaim = $this->executions->findClaimBySchedule($workspaceId, $scheduleId, true);

            if ($existingIntent !== null) {
                if (
                    $existingClaim === null
                    || $existingClaim->state !== CampaignScheduleDueClaimState::Emitted
                    || $existingClaim->id !== $existingIntent->claimId
                    || $existingClaim->version !== $existingIntent->claimVersion + 1
                ) {
                    throw new InvalidArgumentException(
                        'Campaign schedule execution intent is missing canonical emitted claim coordination state.',
                    );
                }

                return $existingClaim;
            }

            if ($existingClaim !== null) {
                if ($existingClaim->state === CampaignScheduleDueClaimState::Emitted) {
                    throw new InvalidArgumentException('Campaign schedule emitted claim is missing its immutable execution intent.');
                }

                if ($existingClaim->replayedBy($leaseOwner, $leaseToken)) {
                    if ($existingClaim->isLeaseActiveAt($observedAt)) {
                        return $existingClaim;
                    }

                    throw new InvalidArgumentException(
                        'Campaign schedule expired lease replay requires a fresh lease token.',
                    );
                }

                if ($existingClaim->isLeaseActiveAt($observedAt)) {
                    throw new InvalidArgumentException('Campaign schedule occurrence is actively leased by another worker.');
                }

                if ($this->executions->hasTerminalScheduleHistory($workspaceId, $scheduleId)) {
                    throw new InvalidArgumentException(
                        'Campaign schedule stale lease cannot be recovered after terminal occurrence history.',
                    );
                }

                $this->assertClaimAuthority($schedule, $existingClaim, $observedAt);

                $replacement = $existingClaim->takeover(
                    leaseOwner: $leaseOwner,
                    leaseToken: $leaseToken,
                    at: $observedAt,
                    leaseExpiresAt: $this->leaseExpiry($observedAt, $leaseSeconds),
                );

                return $this->executions->replaceLease($existingClaim, $replacement);
            }

            $existingOutcome = $this->outcomes->findBySchedule($workspaceId, $scheduleId);
            if ($existingOutcome !== null) {
                return null;
            }

            if ($this->executions->hasTerminalScheduleHistory($workspaceId, $scheduleId)) {
                throw new InvalidArgumentException(
                    'Campaign schedule due claim cannot start after terminal reschedule/cancellation history.',
                );
            }

            if ($observedAt < $schedule->resolvedAtUtc) {
                throw new InvalidArgumentException('Campaign schedule occurrence cannot be claimed before its resolved UTC instant.');
            }

            $campaign = $this->campaigns->lockCampaignForUpdate($workspaceId, $schedule->campaignId);
            if ($campaign->status !== CampaignStatus::ScheduledIntent) {
                throw new InvalidArgumentException('Campaign schedule due claim requires scheduled_intent lifecycle state.');
            }

            $snapshot = $this->campaigns->findSnapshot($workspaceId, $schedule->snapshotId);
            if ($snapshot === null || $snapshot->campaignId !== $campaign->id) {
                throw new InvalidArgumentException('Campaign schedule due claim snapshot binding is not canonical.');
            }

            $evaluation = $this->approvals->evaluate($campaign, $snapshot, $observedAt);

            if (! $evaluation->valid) {
                $this->outcomes->create(CampaignScheduleOccurrenceOutcome::approvalInvalid(
                    id: $this->identifiers->next(),
                    schedule: $schedule,
                    evaluation: $evaluation,
                    recordedByActorId: $leaseOwner,
                    idempotencyKey: 'due-outcome:'.$schedule->id,
                    observedAt: $observedAt,
                ));

                return null;
            }

            if ($observedAt > $schedule->resolvedAtUtc) {
                if ($evaluation->decisionId === null) {
                    throw new InvalidArgumentException(
                        'Campaign schedule late due evaluation requires a concrete approval decision.',
                    );
                }

                $this->outcomes->create(CampaignScheduleOccurrenceOutcome::executionDeadlineMissed(
                    id: $this->identifiers->next(),
                    schedule: $schedule,
                    evaluatedDecisionId: $evaluation->decisionId,
                    recordedByActorId: $leaseOwner,
                    idempotencyKey: 'due-outcome:'.$schedule->id,
                    observedAt: $observedAt,
                ));

                return null;
            }

            if ($evaluation->decisionId === null) {
                throw new InvalidArgumentException(
                    'Campaign schedule due claim requires a concrete approval decision.',
                );
            }

            return $this->executions->createClaim(CampaignScheduleDueClaim::firstLease(
                id: $this->identifiers->next(),
                schedule: $schedule,
                evaluatedApprovalId: $evaluation->decisionId,
                leaseOwner: $leaseOwner,
                leaseToken: $leaseToken,
                claimedAt: $observedAt,
                leaseExpiresAt: $this->leaseExpiry($observedAt, $leaseSeconds),
            ));
        });
    }

    public function emitExecutionIntent(
        string $workspaceId,
        string $scheduleId,
        string $leaseOwner,
        string $leaseToken,
        DateTimeImmutable $at,
    ): CampaignScheduleExecutionIntent {
        CampaignPayloadGuard::assertIdentifier($workspaceId, 'executionIntent.workspaceId');
        CampaignPayloadGuard::assertIdentifier($scheduleId, 'executionIntent.scheduleId');
        CampaignPayloadGuard::assertIdentifier($leaseOwner, 'executionIntent.leaseOwner');
        CampaignPayloadGuard::assertIdentifier($leaseToken, 'executionIntent.leaseToken');

        $emittedAt = $at->setTimezone(new DateTimeZone('UTC'));

        return $this->database->connection()->transaction(function () use (
            $workspaceId,
            $scheduleId,
            $leaseOwner,
            $leaseToken,
            $emittedAt,
        ): CampaignScheduleExecutionIntent {
            $schedule = $this->schedules->lockForUpdate($workspaceId, $scheduleId);
            if ($schedule === null) {
                throw new InvalidArgumentException('Campaign schedule execution intent source does not exist in this workspace.');
            }

            $existing = $this->executions->findIntentBySchedule($workspaceId, $scheduleId, true);
            if ($existing !== null) {
                $emittedClaim = $this->executions->findClaimBySchedule($workspaceId, $scheduleId, true);
                if (
                    $emittedClaim === null
                    || $emittedClaim->state !== CampaignScheduleDueClaimState::Emitted
                    || $emittedClaim->id !== $existing->claimId
                    || $emittedClaim->version !== $existing->claimVersion + 1
                ) {
                    throw new InvalidArgumentException(
                        'Campaign schedule execution intent replay is missing canonical emitted claim state.',
                    );
                }

                return $existing;
            }

            $claim = $this->executions->findClaimBySchedule($workspaceId, $scheduleId, true);
            if ($claim === null) {
                throw new InvalidArgumentException('Campaign schedule execution intent requires a durable due claim.');
            }

            if ($claim->state !== CampaignScheduleDueClaimState::Leased) {
                throw new InvalidArgumentException('Campaign schedule due claim is not in a leaseable execution state.');
            }

            if (! $claim->replayedBy($leaseOwner, $leaseToken)) {
                throw new InvalidArgumentException('Campaign schedule execution intent lease token is stale or foreign.');
            }

            if (! $claim->isLeaseActiveAt($emittedAt)) {
                throw new InvalidArgumentException('Campaign schedule execution intent lease has expired.');
            }

            if ($this->executions->hasTerminalScheduleHistory($workspaceId, $scheduleId)) {
                throw new InvalidArgumentException(
                    'Campaign schedule execution intent cannot emit after terminal occurrence history.',
                );
            }

            $this->assertClaimAuthority($schedule, $claim, $emittedAt);

            $intentId = $this->identifiers->next();
            $outboxId = $this->identifiers->next();
            $intent = CampaignScheduleExecutionIntent::create(
                id: $intentId,
                schedule: $schedule,
                claim: $claim,
                outboxId: $outboxId,
                emittedAt: $emittedAt,
            );

            $this->outbox->store(new OutboxMessage(
                id: $outboxId,
                topic: self::OUTBOX_TOPIC,
                aggregateType: 'campaign_schedule_execution_intent',
                aggregateId: $intent->id,
                payload: [
                    'workspace_id' => $intent->workspaceId,
                    'campaign_id' => $intent->campaignId,
                    'snapshot_id' => $intent->snapshotId,
                    'schedule_id' => $intent->scheduleId,
                    'execution_intent_id' => $intent->id,
                    'intent_hash' => $intent->intentHash,
                    'resolved_at_utc' => $intent->resolvedAtUtc->format('Y-m-d\TH:i:s.u\Z'),
                ],
                headers: [
                    'schema_version' => 1,
                    'source' => 'task0039.scheduler',
                ],
                occurredAt: $emittedAt,
                availableAt: $emittedAt,
            ));

            $stored = $this->executions->createIntent($intent);
            if ($stored->id !== $intent->id) {
                throw new InvalidArgumentException(
                    'Campaign schedule execution intent race produced a non-canonical outbox candidate.',
                );
            }

            $this->executions->markEmitted($claim, $emittedAt);

            return $intent;
        });
    }

    private function assertClaimAuthority(
        \App\Modules\Publishing\Domain\Scheduling\CampaignSchedule $schedule,
        CampaignScheduleDueClaim $claim,
        DateTimeImmutable $at,
    ): void {
        $campaign = $this->campaigns->lockCampaignForUpdate($schedule->workspaceId, $schedule->campaignId);
        if ($campaign->status !== CampaignStatus::ScheduledIntent) {
            throw new InvalidArgumentException(
                'Campaign schedule execution authority requires scheduled_intent lifecycle state.',
            );
        }

        $snapshot = $this->campaigns->findSnapshot($schedule->workspaceId, $schedule->snapshotId);
        if (
            $snapshot === null
            || $snapshot->campaignId !== $campaign->id
            || $snapshot->id !== $claim->snapshotId
        ) {
            throw new InvalidArgumentException(
                'Campaign schedule execution authority snapshot binding is not canonical.',
            );
        }

        $evaluation = $this->approvals->evaluate($campaign, $snapshot, $at);
        if (! $evaluation->valid) {
            throw new InvalidArgumentException(
                'Campaign schedule execution authority is no longer effective: '.$evaluation->reason->value.'.',
            );
        }

        if (
            $evaluation->decisionId === null
            || $evaluation->decisionId !== $claim->evaluatedApprovalId
        ) {
            throw new InvalidArgumentException(
                'Campaign schedule execution authority approval lineage changed after due claiming.',
            );
        }
    }

    private function leaseExpiry(DateTimeImmutable $at, int $leaseSeconds): DateTimeImmutable
    {
        $expiry = $at->modify("+{$leaseSeconds} seconds");

        return $expiry === false ? $at : $expiry;
    }
}
