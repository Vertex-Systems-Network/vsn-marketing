<?php

namespace App\Modules\Publishing\Domain\Publication;

use App\Modules\Publishing\Domain\Campaign\CampaignPayloadGuard;
use InvalidArgumentException;

final readonly class PublicationAggregate
{
    public string $workspaceId;

    public string $executionIntentId;

    public string $campaignId;

    public string $snapshotId;

    /** @var list<PublicationTargetOutcome> */
    public array $targets;

    public PublicationAggregateState $state;

    /** @var list<string> */
    public array $retryAttemptIds;

    public string $aggregateHash;

    /**
     * @param  list<PublicationTargetOutcome>  $targets
     */
    public function __construct(
        string $workspaceId,
        string $executionIntentId,
        string $campaignId,
        string $snapshotId,
        array $targets,
    ) {
        foreach ([
            'workspaceId' => $workspaceId,
            'executionIntentId' => $executionIntentId,
            'campaignId' => $campaignId,
            'snapshotId' => $snapshotId,
        ] as $field => $value) {
            CampaignPayloadGuard::assertIdentifier($value, 'publicationAggregate.'.$field);
        }

        if ($targets === []) {
            throw new InvalidArgumentException('Publication aggregate requires at least one immutable snapshot target.');
        }

        usort(
            $targets,
            static fn (PublicationTargetOutcome $left, PublicationTargetOutcome $right): int => strcmp(
                $left->targetId,
                $right->targetId,
            ),
        );

        $seen = [];
        foreach ($targets as $target) {
            if (isset($seen[$target->targetId])) {
                throw new InvalidArgumentException('Publication aggregate target identities must be unique.');
            }
            $seen[$target->targetId] = true;
        }

        $retryAttemptIds = array_values(array_filter(array_map(
            static fn (PublicationTargetOutcome $target): ?string => $target->retryEligible ? $target->attemptId : null,
            $targets,
        )));
        sort($retryAttemptIds, SORT_STRING);

        $this->workspaceId = $workspaceId;
        $this->executionIntentId = $executionIntentId;
        $this->campaignId = $campaignId;
        $this->snapshotId = $snapshotId;
        $this->targets = $targets;
        $this->state = self::deriveState($targets);
        $this->retryAttemptIds = $retryAttemptIds;
        $this->aggregateHash = CampaignPayloadGuard::hash($this->canonicalPayload());
    }

    /** @return array<string, mixed> */
    public function canonicalPayload(): array
    {
        return [
            'operation' => 'publication.aggregate',
            'workspace_id' => $this->workspaceId,
            'execution_intent_id' => $this->executionIntentId,
            'campaign_id' => $this->campaignId,
            'snapshot_id' => $this->snapshotId,
            'state' => $this->state->value,
            'targets' => array_map(
                static fn (PublicationTargetOutcome $target): array => $target->canonicalPayload(),
                $this->targets,
            ),
            'retry_attempt_ids' => $this->retryAttemptIds,
        ];
    }

    /**
     * @param  list<PublicationTargetOutcome>  $targets
     */
    private static function deriveState(array $targets): PublicationAggregateState
    {
        $states = array_map(
            static fn (PublicationTargetOutcome $target): PublicationTargetOutcomeState => $target->state,
            $targets,
        );
        $successes = count(array_filter(
            $states,
            static fn (PublicationTargetOutcomeState $state): bool => $state === PublicationTargetOutcomeState::Succeeded,
        ));

        if ($successes === count($states)) {
            return PublicationAggregateState::Succeeded;
        }

        if ($successes > 0) {
            return PublicationAggregateState::PartialSuccess;
        }

        if (in_array(PublicationTargetOutcomeState::InProgress, $states, true)) {
            return PublicationAggregateState::InProgress;
        }

        if (
            in_array(PublicationTargetOutcomeState::NotStarted, $states, true)
            || in_array(PublicationTargetOutcomeState::Pending, $states, true)
            || in_array(PublicationTargetOutcomeState::Unknown, $states, true)
            || in_array(PublicationTargetOutcomeState::FailedUnclassified, $states, true)
        ) {
            return PublicationAggregateState::Pending;
        }

        if (in_array(PublicationTargetOutcomeState::FailedRetriable, $states, true)) {
            return PublicationAggregateState::FailedRetriable;
        }

        if (count(array_filter(
            $states,
            static fn (PublicationTargetOutcomeState $state): bool => $state === PublicationTargetOutcomeState::Cancelled,
        )) === count($states)) {
            return PublicationAggregateState::Cancelled;
        }

        return PublicationAggregateState::FailedTerminal;
    }
}
