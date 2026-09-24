<?php

use App\Modules\Providers\Domain\Connectors\ProviderOperationStatus;
use App\Modules\Providers\Domain\Connectors\ReconciliationSource;
use App\Modules\Publishing\Application\Publication\PublicationAttemptService;
use App\Modules\Publishing\Application\Publication\PublicationStatusReconciliationService;
use App\Modules\Publishing\Infrastructure\Persistence\DatabasePublicationStatusRepository;
use DateTimeImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\Support\Publishing\Task0040PublicationFixture;

uses(RefreshDatabase::class);

it('converges duplicate deliveries while preserving the first append-only observation', function () {
    $fixture = Task0040PublicationFixture::create('status-replay');
    $attempt = app(PublicationAttemptService::class)->prepare(
        workspaceId: $fixture['context']->workspaceId,
        executionIntentId: $fixture['executionIntent']->id,
        targetId: $fixture['target']->id,
        at: new DateTimeImmutable('2026-07-15T13:30:20+00:00'),
    );
    $service = app(PublicationStatusReconciliationService::class);

    $first = $service->observe(
        workspaceId: $fixture['context']->workspaceId,
        publicationAttemptId: $attempt->id,
        providerOperationId: 'provider-op-replay',
        normalizedStatus: ProviderOperationStatus::Pending,
        providerStatus: 'PROCESSING',
        source: ReconciliationSource::Webhook,
        sourceReference: 'event-replay-1',
        providerObservedAt: new DateTimeImmutable('2026-07-15T13:30:30+00:00'),
        receivedAt: new DateTimeImmutable('2026-07-15T13:30:31+00:00'),
        evidence: ['code' => 'processing'],
    );
    $duplicate = $service->observe(
        workspaceId: $fixture['context']->workspaceId,
        publicationAttemptId: $attempt->id,
        providerOperationId: 'provider-op-replay',
        normalizedStatus: ProviderOperationStatus::Pending,
        providerStatus: 'PROCESSING',
        source: ReconciliationSource::Webhook,
        sourceReference: 'event-replay-1',
        providerObservedAt: new DateTimeImmutable('2026-07-15T13:30:30+00:00'),
        receivedAt: new DateTimeImmutable('2026-07-15T13:30:50+00:00'),
        evidence: ['code' => 'processing'],
    );

    expect($duplicate->observation->id)->toBe($first->observation->id)
        ->and($duplicate->projection->projectionVersion)->toBe(1)
        ->and($duplicate->projectionAdvanced)->toBeFalse()
        ->and(DB::table('publication_status_observations')->count())->toBe(1)
        ->and(DB::table('publication_status_projections')->count())->toBe(1);
});

it('retains delayed and conflicting observations without overwriting newer terminal evidence', function () {
    $fixture = Task0040PublicationFixture::create('status-order');
    $attempt = app(PublicationAttemptService::class)->prepare(
        workspaceId: $fixture['context']->workspaceId,
        executionIntentId: $fixture['executionIntent']->id,
        targetId: $fixture['target']->id,
        at: new DateTimeImmutable('2026-07-15T13:30:20+00:00'),
    );
    $service = app(PublicationStatusReconciliationService::class);

    $service->observe(
        workspaceId: $fixture['context']->workspaceId,
        publicationAttemptId: $attempt->id,
        providerOperationId: 'provider-op-order',
        normalizedStatus: ProviderOperationStatus::Accepted,
        providerStatus: 'ACCEPTED',
        source: ReconciliationSource::Polling,
        sourceReference: 'poll-accepted',
        providerObservedAt: new DateTimeImmutable('2026-07-15T13:30:30+00:00'),
        receivedAt: new DateTimeImmutable('2026-07-15T13:30:31+00:00'),
    );
    $service->observe(
        workspaceId: $fixture['context']->workspaceId,
        publicationAttemptId: $attempt->id,
        providerOperationId: 'provider-op-order',
        normalizedStatus: ProviderOperationStatus::InProgress,
        providerStatus: 'PROCESSING',
        source: ReconciliationSource::Webhook,
        sourceReference: 'event-progress',
        providerObservedAt: new DateTimeImmutable('2026-07-15T13:31:00+00:00'),
        receivedAt: new DateTimeImmutable('2026-07-15T13:31:01+00:00'),
    );
    $terminal = $service->observe(
        workspaceId: $fixture['context']->workspaceId,
        publicationAttemptId: $attempt->id,
        providerOperationId: 'provider-op-order',
        normalizedStatus: ProviderOperationStatus::Succeeded,
        providerStatus: 'PUBLISHED',
        source: ReconciliationSource::Webhook,
        sourceReference: 'event-published',
        providerObservedAt: new DateTimeImmutable('2026-07-15T13:32:00+00:00'),
        receivedAt: new DateTimeImmutable('2026-07-15T13:32:01+00:00'),
        evidence: ['result' => 'published'],
    );
    $stale = $service->observe(
        workspaceId: $fixture['context']->workspaceId,
        publicationAttemptId: $attempt->id,
        providerOperationId: 'provider-op-order',
        normalizedStatus: ProviderOperationStatus::Pending,
        providerStatus: 'QUEUED',
        source: ReconciliationSource::Webhook,
        sourceReference: 'event-delayed-pending',
        providerObservedAt: new DateTimeImmutable('2026-07-15T13:30:45+00:00'),
        receivedAt: new DateTimeImmutable('2026-07-15T13:33:00+00:00'),
    );
    $conflict = $service->observe(
        workspaceId: $fixture['context']->workspaceId,
        publicationAttemptId: $attempt->id,
        providerOperationId: 'provider-op-order',
        normalizedStatus: ProviderOperationStatus::Failed,
        providerStatus: 'FAILED',
        source: ReconciliationSource::Polling,
        sourceReference: 'poll-conflicting-failure',
        providerObservedAt: new DateTimeImmutable('2026-07-15T13:34:00+00:00'),
        receivedAt: new DateTimeImmutable('2026-07-15T13:34:01+00:00'),
    );

    $repository = app(DatabasePublicationStatusRepository::class);
    $history = $repository->history($fixture['context']->workspaceId, $attempt->id);
    $projection = $repository->findProjection($fixture['context']->workspaceId, $attempt->id);

    expect($terminal->projection->normalizedStatus)->toBe(ProviderOperationStatus::Succeeded)
        ->and($stale->projectionAdvanced)->toBeFalse()
        ->and($conflict->projectionAdvanced)->toBeFalse()
        ->and($history)->toHaveCount(5)
        ->and($projection?->normalizedStatus)->toBe(ProviderOperationStatus::Succeeded)
        ->and($projection?->providerStatus)->toBe('PUBLISHED')
        ->and($projection?->projectionVersion)->toBe(3);
});

it('fails closed when one attempt tries to switch provider operation identity', function () {
    $fixture = Task0040PublicationFixture::create('status-operation-drift');
    $attempt = app(PublicationAttemptService::class)->prepare(
        workspaceId: $fixture['context']->workspaceId,
        executionIntentId: $fixture['executionIntent']->id,
        targetId: $fixture['target']->id,
        at: new DateTimeImmutable('2026-07-15T13:30:20+00:00'),
    );
    $service = app(PublicationStatusReconciliationService::class);

    $service->observe(
        workspaceId: $fixture['context']->workspaceId,
        publicationAttemptId: $attempt->id,
        providerOperationId: 'provider-op-fixed',
        normalizedStatus: ProviderOperationStatus::Accepted,
        providerStatus: 'ACCEPTED',
        source: ReconciliationSource::Polling,
        sourceReference: 'poll-fixed',
        providerObservedAt: new DateTimeImmutable('2026-07-15T13:30:30+00:00'),
        receivedAt: new DateTimeImmutable('2026-07-15T13:30:31+00:00'),
    );

    expect(fn () => $service->observe(
        workspaceId: $fixture['context']->workspaceId,
        publicationAttemptId: $attempt->id,
        providerOperationId: 'provider-op-other',
        normalizedStatus: ProviderOperationStatus::Succeeded,
        providerStatus: 'PUBLISHED',
        source: ReconciliationSource::Webhook,
        sourceReference: 'event-other-operation',
        providerObservedAt: new DateTimeImmutable('2026-07-15T13:31:00+00:00'),
        receivedAt: new DateTimeImmutable('2026-07-15T13:31:01+00:00'),
    ))->toThrow(InvalidArgumentException::class, 'conflicts with canonical attempt reconciliation');

    expect(DB::table('publication_status_observations')->count())->toBe(1);
});

it('rejects foreign-workspace reconciliation and sensitive provider payload evidence', function () {
    $inside = Task0040PublicationFixture::create('status-inside');
    $outside = Task0040PublicationFixture::create('status-outside');
    $attempt = app(PublicationAttemptService::class)->prepare(
        workspaceId: $inside['context']->workspaceId,
        executionIntentId: $inside['executionIntent']->id,
        targetId: $inside['target']->id,
        at: new DateTimeImmutable('2026-07-15T13:30:20+00:00'),
    );
    $service = app(PublicationStatusReconciliationService::class);

    expect(fn () => $service->observe(
        workspaceId: $outside['context']->workspaceId,
        publicationAttemptId: $attempt->id,
        providerOperationId: 'provider-op-foreign',
        normalizedStatus: ProviderOperationStatus::Accepted,
        providerStatus: 'ACCEPTED',
        source: ReconciliationSource::Polling,
        sourceReference: 'poll-foreign',
        providerObservedAt: new DateTimeImmutable('2026-07-15T13:30:30+00:00'),
        receivedAt: new DateTimeImmutable('2026-07-15T13:30:31+00:00'),
    ))->toThrow(AuthorizationException::class, 'Publication attempt access denied');

    expect(fn () => $service->observe(
        workspaceId: $inside['context']->workspaceId,
        publicationAttemptId: $attempt->id,
        providerOperationId: 'provider-op-sensitive',
        normalizedStatus: ProviderOperationStatus::Accepted,
        providerStatus: 'ACCEPTED',
        source: ReconciliationSource::Webhook,
        sourceReference: 'event-sensitive',
        providerObservedAt: new DateTimeImmutable('2026-07-15T13:30:30+00:00'),
        receivedAt: new DateTimeImmutable('2026-07-15T13:30:31+00:00'),
        evidence: ['provider_payload' => ['token' => 'forbidden']],
    ))->toThrow(InvalidArgumentException::class, 'Sensitive or transient provider campaign key is forbidden');

    expect(DB::table('publication_status_observations')->count())->toBe(0);
});
