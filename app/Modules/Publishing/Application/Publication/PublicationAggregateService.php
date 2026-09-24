<?php

namespace App\Modules\Publishing\Application\Publication;

use App\Modules\Publishing\Domain\Campaign\CampaignPayloadGuard;
use App\Modules\Publishing\Domain\Publication\PublicationAggregate;
use App\Modules\Publishing\Domain\Publication\PublicationTargetOutcome;
use App\Modules\Publishing\Infrastructure\Persistence\DatabaseCampaignRepository;
use App\Modules\Publishing\Infrastructure\Persistence\DatabaseCampaignScheduleExecutionRepository;
use App\Modules\Publishing\Infrastructure\Persistence\DatabasePublicationAttemptRepository;
use App\Modules\Publishing\Infrastructure\Persistence\DatabasePublicationStatusRepository;
use InvalidArgumentException;

final readonly class PublicationAggregateService
{
    public function __construct(
        private DatabaseCampaignRepository $campaigns,
        private DatabaseCampaignScheduleExecutionRepository $executions,
        private DatabasePublicationAttemptRepository $attempts,
        private DatabasePublicationStatusRepository $statuses,
    ) {}

    public function forExecutionIntent(string $workspaceId, string $executionIntentId): PublicationAggregate
    {
        CampaignPayloadGuard::assertIdentifier($workspaceId, 'publicationAggregate.workspaceId');
        CampaignPayloadGuard::assertIdentifier($executionIntentId, 'publicationAggregate.executionIntentId');

        $intent = $this->executions->findIntent($workspaceId, $executionIntentId);
        if ($intent === null) {
            throw new InvalidArgumentException('Publication aggregate execution intent does not exist in this workspace.');
        }

        $snapshot = $this->campaigns->findSnapshot($workspaceId, $intent->snapshotId);
        if ($snapshot === null || $snapshot->campaignId !== $intent->campaignId) {
            throw new InvalidArgumentException('Publication aggregate execution intent is not bound to its canonical snapshot.');
        }

        $attempts = [];
        foreach ($this->attempts->forExecutionIntent($workspaceId, $intent->id) as $attempt) {
            if (isset($attempts[$attempt->targetId])) {
                throw new InvalidArgumentException('Publication aggregate found duplicate canonical attempts for one target.');
            }
            $attempts[$attempt->targetId] = $attempt;
        }

        $outcomes = [];
        foreach ($snapshot->targets as $target) {
            $attempt = $attempts[$target->id] ?? null;
            $projection = $attempt === null
                ? null
                : $this->statuses->findProjection($workspaceId, $attempt->id);

            $outcomes[] = PublicationTargetOutcome::fromEvidence(
                targetId: $target->id,
                channel: $target->channel,
                attempt: $attempt,
                projection: $projection,
            );
            unset($attempts[$target->id]);
        }

        if ($attempts !== []) {
            throw new InvalidArgumentException('Publication aggregate contains an attempt outside the immutable snapshot target set.');
        }

        return new PublicationAggregate(
            workspaceId: $workspaceId,
            executionIntentId: $intent->id,
            campaignId: $intent->campaignId,
            snapshotId: $intent->snapshotId,
            targets: $outcomes,
        );
    }
}
