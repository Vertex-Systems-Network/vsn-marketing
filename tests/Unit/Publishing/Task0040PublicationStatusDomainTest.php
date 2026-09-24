<?php

use App\Modules\Providers\Domain\Connectors\ProviderOperationStatus;
use App\Modules\Providers\Domain\Connectors\ReconciliationSource;
use App\Modules\Publishing\Domain\Publication\PublicationStatusObservation;
use App\Modules\Publishing\Domain\Publication\PublicationStatusProjection;
use DateTimeImmutable;
use InvalidArgumentException;

function task0040StatusObservation(
    string $id,
    ProviderOperationStatus $status,
    string $providerStatus,
    string $sourceReference,
    string $providerObservedAt,
    string $receivedAt,
    string $providerOperationId = 'provider-op-1',
    ReconciliationSource $source = ReconciliationSource::Webhook,
): PublicationStatusObservation {
    return PublicationStatusObservation::record(
        id: $id,
        workspaceId: 'workspace-1',
        publicationAttemptId: 'attempt-1',
        providerConnectionId: 'connection-1',
        capabilityEvidenceId: 'capability-1',
        providerId: 'provider-1',
        providerOperationId: $providerOperationId,
        normalizedStatus: $status,
        providerStatus: $providerStatus,
        source: $source,
        sourceReference: $sourceReference,
        providerObservedAt: new DateTimeImmutable($providerObservedAt),
        receivedAt: new DateTimeImmutable($receivedAt),
        evidence: ['code' => $providerStatus],
    );
}

it('derives duplicate identity from provider evidence rather than local receipt time', function () {
    $first = task0040StatusObservation(
        'observation-1',
        ProviderOperationStatus::Pending,
        'PROCESSING',
        'event-42',
        '2026-07-15T13:30:30+00:00',
        '2026-07-15T13:30:31+00:00',
    );
    $duplicate = task0040StatusObservation(
        'observation-2',
        ProviderOperationStatus::Pending,
        'PROCESSING',
        'event-42',
        '2026-07-15T13:30:30+00:00',
        '2026-07-15T13:31:00+00:00',
    );

    expect($duplicate->idempotencyKey)->toBe($first->idempotencyKey)
        ->and($duplicate->observationHash)->toBe($first->observationHash)
        ->and($duplicate->receivedAt)->not->toEqual($first->receivedAt);
});

it('keeps projection monotonic while allowing append-only stale and terminal-conflicting evidence', function () {
    $accepted = task0040StatusObservation(
        'observation-accepted',
        ProviderOperationStatus::Accepted,
        'ACCEPTED',
        'poll-1',
        '2026-07-15T13:30:30+00:00',
        '2026-07-15T13:30:31+00:00',
        source: ReconciliationSource::Polling,
    );
    $inProgress = task0040StatusObservation(
        'observation-progress',
        ProviderOperationStatus::InProgress,
        'PROCESSING',
        'event-progress',
        '2026-07-15T13:31:00+00:00',
        '2026-07-15T13:31:01+00:00',
    );
    $stalePending = task0040StatusObservation(
        'observation-stale',
        ProviderOperationStatus::Pending,
        'QUEUED',
        'event-stale',
        '2026-07-15T13:30:45+00:00',
        '2026-07-15T13:31:05+00:00',
    );
    $succeeded = task0040StatusObservation(
        'observation-success',
        ProviderOperationStatus::Succeeded,
        'PUBLISHED',
        'event-success',
        '2026-07-15T13:32:00+00:00',
        '2026-07-15T13:32:01+00:00',
    );
    $conflictingFailure = task0040StatusObservation(
        'observation-conflict',
        ProviderOperationStatus::Failed,
        'FAILED',
        'event-conflict',
        '2026-07-15T13:33:00+00:00',
        '2026-07-15T13:33:01+00:00',
    );

    $initial = PublicationStatusProjection::initial($accepted);
    $progress = $initial->apply($inProgress);
    $afterStale = $progress->apply($stalePending);
    $terminal = $afterStale->apply($succeeded);
    $afterConflict = $terminal->apply($conflictingFailure);

    expect($progress->normalizedStatus)->toBe(ProviderOperationStatus::InProgress)
        ->and($progress->projectionVersion)->toBe(2)
        ->and($afterStale)->toBe($progress)
        ->and($terminal->normalizedStatus)->toBe(ProviderOperationStatus::Succeeded)
        ->and($terminal->projectionVersion)->toBe(3)
        ->and($afterConflict)->toBe($terminal);
});

it('rejects a different provider operation identity from the same canonical projection', function () {
    $initial = PublicationStatusProjection::initial(task0040StatusObservation(
        'observation-1',
        ProviderOperationStatus::Pending,
        'PENDING',
        'event-1',
        '2026-07-15T13:30:30+00:00',
        '2026-07-15T13:30:31+00:00',
    ));

    expect(fn () => $initial->apply(task0040StatusObservation(
        'observation-2',
        ProviderOperationStatus::Succeeded,
        'PUBLISHED',
        'event-2',
        '2026-07-15T13:31:00+00:00',
        '2026-07-15T13:31:01+00:00',
        providerOperationId: 'provider-op-other',
    )))->toThrow(InvalidArgumentException::class, 'canonical provider operation ID');
});
