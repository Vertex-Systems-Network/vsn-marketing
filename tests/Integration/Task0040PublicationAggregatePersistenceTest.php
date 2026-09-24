<?php

use App\Modules\Providers\Domain\Connectors\ProviderOperationStatus;
use App\Modules\Providers\Domain\Connectors\ReconciliationSource;
use App\Modules\Publishing\Application\Publication\PublicationAggregateService;
use App\Modules\Publishing\Application\Publication\PublicationAttemptService;
use App\Modules\Publishing\Application\Publication\PublicationStatusReconciliationService;
use App\Modules\Publishing\Domain\Publication\PublicationAggregateState;
use App\Modules\Publishing\Domain\Publication\PublicationAttemptState;
use App\Modules\Publishing\Infrastructure\Persistence\DatabasePublicationAttemptRepository;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\Publishing\Task0040PublicationFixture;

uses(RefreshDatabase::class);

beforeEach(function () {
    if (! filter_var(env('RUN_INFRA_INTEGRATION', false), FILTER_VALIDATE_BOOL)) {
        $this->markTestSkipped('Set RUN_INFRA_INTEGRATION=true to run TASK-0040 aggregate PostgreSQL tests.');
    }

    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('TASK-0040 aggregate persistence certification requires PostgreSQL.');
    }
});

it('keeps aggregate and retry selection stable under duplicate and stale provider evidence', function () {
    $fixture = Task0040PublicationFixture::create('aggregate-pgsql', false, 2);
    $attemptService = app(PublicationAttemptService::class);
    $attempts = app(DatabasePublicationAttemptRepository::class);
    $status = app(PublicationStatusReconciliationService::class);

    $first = $attemptService->prepare(
        workspaceId: $fixture['context']->workspaceId,
        executionIntentId: $fixture['executionIntent']->id,
        targetId: $fixture['targets'][0]->id,
        at: new DateTimeImmutable('2026-07-15T13:30:20+00:00'),
    );
    $second = $attemptService->prepare(
        workspaceId: $fixture['context']->workspaceId,
        executionIntentId: $fixture['executionIntent']->id,
        targetId: $fixture['targets'][1]->id,
        at: new DateTimeImmutable('2026-07-15T13:30:20+00:00'),
    );

    $first = $attempts->transitionState(
        $first->transitionTo(PublicationAttemptState::Dispatching, new DateTimeImmutable('2026-07-15T13:30:21+00:00')),
        1,
    );
    $first = $attempts->transitionState(
        $first->transitionTo(PublicationAttemptState::Published, new DateTimeImmutable('2026-07-15T13:30:22+00:00')),
        2,
    );
    $second = $attempts->transitionState(
        $second->transitionTo(PublicationAttemptState::Dispatching, new DateTimeImmutable('2026-07-15T13:30:21+00:00')),
        1,
    );
    $second = $attempts->transitionState(
        $second->transitionTo(PublicationAttemptState::FailedRetriable, new DateTimeImmutable('2026-07-15T13:30:22+00:00')),
        2,
    );

    $status->observe(
        workspaceId: $fixture['context']->workspaceId,
        publicationAttemptId: $first->id,
        providerOperationId: 'provider-op-pg-success',
        normalizedStatus: ProviderOperationStatus::Succeeded,
        providerStatus: 'PUBLISHED',
        source: ReconciliationSource::Webhook,
        sourceReference: 'event-pg-success',
        providerObservedAt: new DateTimeImmutable('2026-07-15T13:31:00+00:00'),
        receivedAt: new DateTimeImmutable('2026-07-15T13:31:01+00:00'),
    );
    $status->observe(
        workspaceId: $fixture['context']->workspaceId,
        publicationAttemptId: $second->id,
        providerOperationId: 'provider-op-pg-retry',
        normalizedStatus: ProviderOperationStatus::Failed,
        providerStatus: 'TEMPORARY_FAILURE',
        source: ReconciliationSource::Webhook,
        sourceReference: 'event-pg-retry',
        providerObservedAt: new DateTimeImmutable('2026-07-15T13:31:00+00:00'),
        receivedAt: new DateTimeImmutable('2026-07-15T13:31:01+00:00'),
    );

    $service = app(PublicationAggregateService::class);
    $before = $service->forExecutionIntent($fixture['context']->workspaceId, $fixture['executionIntent']->id);

    $status->observe(
        workspaceId: $fixture['context']->workspaceId,
        publicationAttemptId: $first->id,
        providerOperationId: 'provider-op-pg-success',
        normalizedStatus: ProviderOperationStatus::InProgress,
        providerStatus: 'PROCESSING_STALE',
        source: ReconciliationSource::Polling,
        sourceReference: 'poll-stale-after-success',
        providerObservedAt: new DateTimeImmutable('2026-07-15T13:30:40+00:00'),
        receivedAt: new DateTimeImmutable('2026-07-15T13:32:00+00:00'),
    );
    $status->observe(
        workspaceId: $fixture['context']->workspaceId,
        publicationAttemptId: $second->id,
        providerOperationId: 'provider-op-pg-retry',
        normalizedStatus: ProviderOperationStatus::Failed,
        providerStatus: 'TEMPORARY_FAILURE',
        source: ReconciliationSource::Webhook,
        sourceReference: 'event-pg-retry',
        providerObservedAt: new DateTimeImmutable('2026-07-15T13:31:00+00:00'),
        receivedAt: new DateTimeImmutable('2026-07-15T13:32:10+00:00'),
    );

    $after = $service->forExecutionIntent($fixture['context']->workspaceId, $fixture['executionIntent']->id);

    expect($before->state)->toBe(PublicationAggregateState::PartialSuccess)
        ->and($before->retryAttemptIds)->toBe([$second->id])
        ->and($after->state)->toBe(PublicationAggregateState::PartialSuccess)
        ->and($after->retryAttemptIds)->toBe([$second->id])
        ->and($after->aggregateHash)->toBe($before->aggregateHash)
        ->and(DB::table('publication_status_observations')->count())->toBe(3);
});
