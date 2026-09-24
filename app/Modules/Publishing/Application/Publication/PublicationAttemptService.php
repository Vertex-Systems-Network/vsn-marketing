<?php

namespace App\Modules\Publishing\Application\Publication;

use App\Modules\Core\Domain\Contracts\IdentifierGenerator;
use App\Modules\Providers\Domain\CapabilitySupport;
use App\Modules\Providers\Domain\Contracts\ProviderRepository;
use App\Modules\Publishing\Application\Governance\CampaignApprovalEvaluator;
use App\Modules\Publishing\Domain\Campaign\CampaignPayloadGuard;
use App\Modules\Publishing\Domain\Campaign\CampaignStatus;
use App\Modules\Publishing\Domain\Campaign\CampaignTargetKind;
use App\Modules\Publishing\Domain\Publication\PublicationAttempt;
use App\Modules\Publishing\Infrastructure\Persistence\DatabaseCampaignRepository;
use App\Modules\Publishing\Infrastructure\Persistence\DatabaseCampaignScheduleExecutionRepository;
use App\Modules\Publishing\Infrastructure\Persistence\DatabasePublicationAttemptRepository;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;

final readonly class PublicationAttemptService
{
    public function __construct(
        private DatabaseManager $database,
        private IdentifierGenerator $identifiers,
        private DatabaseCampaignRepository $campaigns,
        private DatabaseCampaignScheduleExecutionRepository $executions,
        private DatabasePublicationAttemptRepository $attempts,
        private CampaignApprovalEvaluator $approvals,
        private ProviderRepository $providers,
    ) {}

    public function prepare(
        string $workspaceId,
        string $executionIntentId,
        string $targetId,
        DateTimeImmutable $at,
    ): PublicationAttempt {
        CampaignPayloadGuard::assertIdentifier($workspaceId, 'publicationAttempt.workspaceId');
        CampaignPayloadGuard::assertIdentifier($executionIntentId, 'publicationAttempt.executionIntentId');
        CampaignPayloadGuard::assertIdentifier($targetId, 'publicationAttempt.targetId');

        $preparedAt = $at->setTimezone(new DateTimeZone('UTC'));

        return $this->database->connection()->transaction(function () use (
            $workspaceId,
            $executionIntentId,
            $targetId,
            $preparedAt,
        ): PublicationAttempt {
            $intent = $this->executions->findIntent($workspaceId, $executionIntentId, true);
            if ($intent === null) {
                throw new InvalidArgumentException('Publication attempt execution intent does not exist in this workspace.');
            }

            $campaign = $this->campaigns->findCampaign($workspaceId, $intent->campaignId);
            $snapshot = $this->campaigns->findSnapshot($workspaceId, $intent->snapshotId);

            if (
                $campaign === null
                || $snapshot === null
                || $campaign->status !== CampaignStatus::ScheduledIntent
                || $snapshot->campaignId !== $campaign->id
                || $intent->campaignId !== $campaign->id
                || $intent->snapshotId !== $snapshot->id
            ) {
                throw new InvalidArgumentException(
                    'Publication attempt execution intent is not bound to the current canonical campaign snapshot.',
                );
            }

            $evaluation = $this->approvals->evaluate($campaign, $snapshot, $preparedAt);
            if (! $evaluation->valid || $evaluation->reason !== null) {
                throw new InvalidArgumentException(
                    'Publication attempt authority is no longer effective: '.($evaluation->reason?->value ?? 'unknown').'.',
                );
            }

            if ($evaluation->decisionId !== $intent->evaluatedApprovalId) {
                throw new InvalidArgumentException(
                    'Publication attempt approval lineage changed after execution-intent emission.',
                );
            }

            $target = null;
            foreach ($snapshot->targets as $candidate) {
                if ($candidate->id === $targetId) {
                    $target = $candidate;
                    break;
                }
            }

            if ($target === null) {
                throw new InvalidArgumentException('Publication attempt target is not part of the immutable snapshot.');
            }

            if (
                $target->kind !== CampaignTargetKind::ProviderConnection
                || $target->providerConnectionId === null
                || $target->capabilityEvidenceId === null
            ) {
                throw new InvalidArgumentException(
                    'Publication attempt foundation requires an exact provider-connection target and capability evidence.',
                );
            }

            $connection = $this->providers->findConnection($workspaceId, $target->providerConnectionId);
            $capability = $this->providers->findCapability($workspaceId, $target->capabilityEvidenceId);

            if ($connection === null || $capability === null) {
                throw new InvalidArgumentException('Publication attempt provider authority is unavailable.');
            }

            if (
                $capability->operation !== 'publication.create'
                || $capability->support !== CapabilitySupport::Supported
                || $capability->providerId !== $connection->providerId
                || $capability->connectionId !== $connection->id
            ) {
                throw new InvalidArgumentException(
                    'Publication attempt requires exact supported publication.create capability evidence.',
                );
            }

            return $this->attempts->create(PublicationAttempt::prepare(
                id: $this->identifiers->next(),
                workspaceId: $workspaceId,
                executionIntentId: $intent->id,
                campaignId: $intent->campaignId,
                snapshotId: $intent->snapshotId,
                targetId: $target->id,
                targetHash: $target->fingerprint(),
                channel: $target->channel,
                providerConnectionId: $connection->id,
                capabilityEvidenceId: $capability->id,
                providerId: $connection->providerId,
                createdAt: $preparedAt,
            ));
        });
    }
}
