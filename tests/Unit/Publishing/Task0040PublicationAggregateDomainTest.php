<?php

use App\Modules\Providers\Domain\Connectors\ProviderOperationStatus;
use App\Modules\Providers\Domain\Connectors\ReconciliationSource;
use App\Modules\Publishing\Domain\Publication\PublicationAggregate;
use App\Modules\Publishing\Domain\Publication\PublicationAggregateState;
use App\Modules\Publishing\Domain\Publication\PublicationAttempt;
use App\Modules\Publishing\Domain\Publication\PublicationAttemptState;
use App\Modules\Publishing\Domain\Publication\PublicationStatusProjection;
use App\Modules\Publishing\Domain\Publication\PublicationTargetOutcome;
use App\Modules\Publishing\Domain\Publication\PublicationTargetOutcomeState;
use DateTimeImmutable;
use InvalidArgumentException;

function task0040AggregateAttempt(string $id, string $targetId, string $channel): PublicationAttempt
{
    return PublicationAttempt::prepare(
        id: $id,
        workspaceId: 'workspace-1',
        executionIntentId: 'intent-1',
        campaignId: 'campaign-1',
        snapshotId: 'snapshot-1',
        targetId: $targetId,
        targetHash: hash('sha256', 'target-'.$targetId),
        channel: $channel,
        providerConnectionId: 'connection-1',
        capabilityEvidenceId: 'capability-1',
        providerId: 'provider-1',
        createdAt: new DateTimeImmutable('2026-07-15T13:30:20+00:00'),
    );
}

function task0040AggregateProjection(
    PublicationAttempt $attempt,
    ProviderOperationStatus $status,
    string $suffix,
): PublicationStatusProjection {
    return new PublicationStatusProjection(
        workspaceId: $attempt->workspaceId,
        publicationAttemptId: $attempt->id,
        providerConnectionId: $attempt->providerConnectionId,
        capabilityEvidenceId: $attempt->capabilityEvidenceId,
        providerId: $attempt->providerId,
        providerOperationId: 'provider-op-'.$suffix,
        normalizedStatus: $status,
        providerStatus: strtoupper($status->value),
        providerObservedAt: new DateTimeImmutable('2026-07-15T13:31:00+00:00'),
        source: ReconciliationSource::Webhook,
        sourceReference: 'event-'.$suffix,
        currentObservationId: 'observation-'.$suffix,
        currentObservationHash: hash('sha256', 'observation-'.$suffix),
        projectionVersion: 1,
        updatedAt: new DateTimeImmutable('2026-07-15T13:31:01+00:00'),
    );
}

it('represents partial success deterministically and selects only trusted retriable failures', function () {
    $success = task0040AggregateAttempt('attempt-success', 'target-success', 'social')
        ->transitionTo(PublicationAttemptState::Dispatching, new DateTimeImmutable('2026-07-15T13:30:21+00:00'))
        ->transitionTo(PublicationAttemptState::Published, new DateTimeImmutable('2026-07-15T13:30:22+00:00'));
    $retry = task0040AggregateAttempt('attempt-retry', 'target-retry', 'social_2')
        ->transitionTo(PublicationAttemptState::Dispatching, new DateTimeImmutable('2026-07-15T13:30:21+00:00'))
        ->transitionTo(PublicationAttemptState::FailedRetriable, new DateTimeImmutable('2026-07-15T13:30:22+00:00'));

    $successOutcome = PublicationTargetOutcome::fromEvidence(
        targetId: $success->targetId,
        channel: $success->channel,
        attempt: $success,
        projection: task0040AggregateProjection($success, ProviderOperationStatus::Succeeded, 'success'),
    );
    $retryOutcome = PublicationTargetOutcome::fromEvidence(
        targetId: $retry->targetId,
        channel: $retry->channel,
        attempt: $retry,
        projection: task0040AggregateProjection($retry, ProviderOperationStatus::Failed, 'retry'),
    );
    $notStarted = PublicationTargetOutcome::fromEvidence(
        targetId: 'target-pending',
        channel: 'social_3',
        attempt: null,
        projection: null,
    );

    $aggregate = new PublicationAggregate(
        workspaceId: 'workspace-1',
        executionIntentId: 'intent-1',
        campaignId: 'campaign-1',
        snapshotId: 'snapshot-1',
        targets: [$retryOutcome, $notStarted, $successOutcome],
    );
    $reordered = new PublicationAggregate(
        workspaceId: 'workspace-1',
        executionIntentId: 'intent-1',
        campaignId: 'campaign-1',
        snapshotId: 'snapshot-1',
        targets: [$successOutcome, $retryOutcome, $notStarted],
    );

    expect($aggregate->state)->toBe(PublicationAggregateState::PartialSuccess)
        ->and($aggregate->retryAttemptIds)->toBe(['attempt-retry'])
        ->and($aggregate->aggregateHash)->toBe($reordered->aggregateHash)
        ->and($aggregate->targets[0]->targetId)->toBe('target-pending')
        ->and($successOutcome->retryEligible)->toBeFalse()
        ->and($retryOutcome->retryEligible)->toBeTrue()
        ->and($notStarted->state)->toBe(PublicationTargetOutcomeState::NotStarted);
});

it('never collapses a mixed target set into false global success', function () {
    $success = task0040AggregateAttempt('attempt-success-2', 'target-success-2', 'social')
        ->transitionTo(PublicationAttemptState::Dispatching, new DateTimeImmutable('2026-07-15T13:30:21+00:00'))
        ->transitionTo(PublicationAttemptState::Published, new DateTimeImmutable('2026-07-15T13:30:22+00:00'));
    $failed = task0040AggregateAttempt('attempt-failed-2', 'target-failed-2', 'social_2')
        ->transitionTo(PublicationAttemptState::Dispatching, new DateTimeImmutable('2026-07-15T13:30:21+00:00'))
        ->transitionTo(PublicationAttemptState::FailedTerminal, new DateTimeImmutable('2026-07-15T13:30:22+00:00'));

    $aggregate = new PublicationAggregate(
        workspaceId: 'workspace-1',
        executionIntentId: 'intent-1',
        campaignId: 'campaign-1',
        snapshotId: 'snapshot-1',
        targets: [
            PublicationTargetOutcome::fromEvidence(
                $success->targetId,
                $success->channel,
                $success,
                task0040AggregateProjection($success, ProviderOperationStatus::Succeeded, 'success-2'),
            ),
            PublicationTargetOutcome::fromEvidence(
                $failed->targetId,
                $failed->channel,
                $failed,
                task0040AggregateProjection($failed, ProviderOperationStatus::Failed, 'failed-2'),
            ),
        ],
    );

    expect($aggregate->state)->toBe(PublicationAggregateState::PartialSuccess)
        ->and($aggregate->state)->not->toBe(PublicationAggregateState::Succeeded)
        ->and($aggregate->retryAttemptIds)->toBe([]);
});

it('rejects forged retry eligibility on a successful target', function () {
    expect(fn () => new PublicationTargetOutcome(
        targetId: 'target-success',
        channel: 'social',
        attemptId: 'attempt-success',
        attemptHash: str_repeat('a', 64),
        attemptState: PublicationAttemptState::Published,
        providerStatus: ProviderOperationStatus::Succeeded,
        projectionObservationHash: str_repeat('b', 64),
        projectionVersion: 1,
        state: PublicationTargetOutcomeState::Succeeded,
        retryEligible: true,
    ))->toThrow(InvalidArgumentException::class, 'retry eligibility');
});
