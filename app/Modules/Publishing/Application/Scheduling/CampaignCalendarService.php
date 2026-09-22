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
use App\Modules\Publishing\Domain\Scheduling\LocalScheduleTimeResolver;
use App\Modules\Publishing\Infrastructure\Persistence\DatabaseCampaignRepository;
use App\Modules\Publishing\Infrastructure\Persistence\DatabaseCampaignScheduleRepository;
use DateTimeImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;

final readonly class CampaignCalendarService
{
    public function __construct(
        private DatabaseCampaignRepository $campaigns,
        private DatabaseCampaignScheduleRepository $schedules,
        private CampaignApprovalEvaluator $approvals,
        private LocalScheduleTimeResolver $resolver,
        private WorkspaceAuthorizer $authorizer,
        private DatabaseManager $database,
    ) {}

    public function scheduleFixedInstant(
        User $actor,
        TenantContext $context,
        string $campaignId,
        string $snapshotId,
        string $scheduleId,
        string $idempotencyKey,
        DateTimeImmutable $at,
    ): CampaignSchedule {
        if (! $this->authorizer->allows($actor, $context, PermissionCatalog::CAMPAIGN_SEND)) {
            throw new AuthorizationException('Campaign scheduling requires campaign.send permission.');
        }

        return $this->database->connection()->transaction(function () use (
            $context,
            $campaignId,
            $snapshotId,
            $scheduleId,
            $idempotencyKey,
            $at,
        ): CampaignSchedule {
            $campaign = $this->campaigns->lockCampaignForUpdate($context->workspaceId, $campaignId);

            if ($campaign->status !== CampaignStatus::ScheduledIntent) {
                throw new InvalidArgumentException('Campaign calendar scheduling requires scheduled_intent lifecycle state.');
            }

            $snapshot = $this->campaigns->findSnapshot($context->workspaceId, $snapshotId);
            if (! $snapshot instanceof CampaignSnapshot || $snapshot->campaignId !== $campaign->id) {
                throw new InvalidArgumentException('Campaign calendar scheduling requires the exact immutable campaign snapshot.');
            }

            $evaluation = $this->approvals->evaluate($campaign, $snapshot, $at);
            $this->assertEffectiveApproval($evaluation);

            $execution = $snapshot->intendedExecution;
            if (($execution['mode'] ?? null) !== 'fixed_instant') {
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

            $schedule = CampaignSchedule::fixedInstant(
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
            );

            return $this->schedules->create($schedule);
        });
    }

    private function assertEffectiveApproval(CampaignApprovalEvaluation $evaluation): void
    {
        if ($evaluation->valid) {
            return;
        }

        $reason = $evaluation->reason?->value ?? 'unknown';

        throw new InvalidArgumentException(
            'Campaign calendar scheduling requires effective approval: '.$reason.'.',
        );
    }
}
