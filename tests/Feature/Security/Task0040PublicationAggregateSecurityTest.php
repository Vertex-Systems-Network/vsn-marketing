<?php

use App\Modules\Providers\Domain\Connectors\ProviderOperationStatus;
use App\Modules\Providers\Domain\Connectors\ReconciliationSource;
use App\Modules\Publishing\Application\Publication\PublicationAggregateService;
use App\Modules\Publishing\Application\Publication\PublicationAttemptService;
use App\Modules\Publishing\Application\Publication\PublicationStatusReconciliationService;
use App\Modules\Publishing\Domain\Publication\PublicationAggregateState;
use App\Modules\Publishing\Domain\Publication\PublicationAttemptState;
use App\Modules\Publishing\Domain\Publication\PublicationTargetOutcomeState;
use App\Modules\Publishing\Infrastructure\Persistence\DatabasePublicationAttemptRepository;
use DateTimeImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Publishing\Task0040PublicationFixture;

uses(RefreshDatabase::class);

it('keeps successful targets out of retry selection during partial multi-target failure', function () {
    $fixture = Task0040PublicationFixture::create('aggregate-partial', false, 3);
    $attemptService = app(PublicationAttemptService::class);
    $attempts = app(DatabasePublicationAttemptRepository::class);
    $status = app(PublicationStatusReconciliationService::class);

    $prepared = [];
    foreach ($fixture['targets'] as $index => $target) {
        $prepared[$index] = $attemptService->prepare(
            workspaceId: $fixture['context']->workspaceId,
            executionIntentId: $fixture['executionIntent']->id,
            targetId: $target->id,
            at: new DateTimeImmutable('2026-07-15T13:30:20+00:00'),
        );
    }

    $success = $attempts->transitionState(
        $prepared[0]->transitionTo(
            PublicationAttemptState::Dispatching,
            new DateTimeImmutable('2026-07-15T13:30:21+00:00'),
        ),
        1,
    );
    $success = $attempts->transitionState(
        $success->transitionTo(
            PublicationAttemptState::Published,
            new DateTimeImmutable('2026-07-15T13:30:22+00:00'),
        ),
        2,
    );

    $retry = $attempts->transitionState(
        $prepared[1]->transitionTo(
            PublicationAttemptState::Dispatching,
            new DateTimeImmutable('2026-07-15T13:30:21+00:00'),
        ),
        1,
    );
    $retry = $attempts->transitionState(
        $retry->transitionTo(
            PublicationAttemptState::FailedRetriable,
            new DateTimeImmutable('2026-07-15T13:30:22+00:00'),
        ),
        2,
    );

    $status->observe(
        workspaceId: $fixture['context']->workspaceId,
        publicationAttemptId: $success->id,
        providerOperationId: 'provider-op-success',
        normalizedStatus: ProviderOperationStatus::Succeeded,
        providerStatus: 'PUBLISHED',
        source: ReconciliationSource::Webhook,
        sourceReference: 'event-success',
        providerObservedAt: new DateTimeImmutable('2026-07-15T13:31:00+00:00'),
        receivedAt: new DateTimeImmutable('2026-07-15T13:31:01+00:00'),
    );
    $status->observe(
        workspaceId: $fixture['context']->workspaceId,
        publicationAttemptId: $retry->id,
        providerOperationId: 'provider-op-retry',
        normalizedStatus: ProviderOperationStatus::Failed,
        providerStatus: 'TEMPORARY_FAILURE',
        source: ReconciliationSource::Webhook,
        sourceReference: 'event-retry',
        providerObservedAt: new DateTimeImmutable('2026-07-15T13:31:00+00:00'),
        receivedAt: new DateTimeImmutable('2026-07-15T13:31:01+00:00'),
    );

    $aggregate = app(PublicationAggregateService::class)->forExecutionIntent(
        $fixture['context']->workspaceId,
        $fixture['executionIntent']->id,
    );

    $byTarget = [];
    foreach ($aggregate->targets as $outcome) {
        $byTarget[$outcome->targetId] = $outcome;
    }

    expect($aggregate->state)->toBe(PublicationAggregateState::PartialSuccess)
        ->and($aggregate->retryAttemptIds)->toBe([$retry->id])
        ->and($byTarget[$fixture['targets'][0]->id]->state)->toBe(PublicationTargetOutcomeState::Succeeded)
        ->and($byTarget[$fixture['targets'][0]->id]->retryEligible)->toBeFalse()
        ->and($byTarget[$fixture['targets'][1]->id]->state)->toBe(PublicationTargetOutcomeState::FailedRetriable)
        ->and($byTarget[$fixture['targets'][1]->id]->retryEligible)->toBeTrue()
        ->and($byTarget[$fixture['targets'][2]->id]->state)->toBe(PublicationTargetOutcomeState::Pending)
        ->and($byTarget[$fixture['targets'][2]->id]->retryEligible)->toBeFalse();
});

it('fails closed when another workspace asks for an aggregate', function () {
    $inside = Task0040PublicationFixture::create('aggregate-inside', false, 2);
    $outside = Task0040PublicationFixture::create('aggregate-outside', false, 2);

    expect(fn () => app(PublicationAggregateService::class)->forExecutionIntent(
        $outside['context']->workspaceId,
        $inside['executionIntent']->id,
    ))->toThrow(AuthorizationException::class, 'execution intent access denied');
});
