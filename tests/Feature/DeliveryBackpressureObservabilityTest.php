<?php

use App\Modules\Core\Domain\Contracts\Clock;
use App\Modules\DeliveryEngine\Application\DescribeDeliveryBackpressure;
use App\Modules\DeliveryEngine\Domain\DeliveryChannel;
use App\Modules\DeliveryEngine\Domain\DeliveryOperation;
use App\Modules\DeliveryEngine\Domain\DeliveryOperationState;
use App\Modules\DeliveryEngine\Domain\DeliveryPriorityClass;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;

function backpressureOperation(
    DeliveryOperationState $state,
    ?string $reason,
    ?DateTimeImmutable $backpressuredAt,
    string $workspaceId = 'workspace-a',
): DeliveryOperation {
    $createdAt = new DateTimeImmutable('2026-09-08T14:55:00+00:00');

    return new DeliveryOperation(
        id: 'operation-1',
        workspaceId: $workspaceId,
        messageSnapshotId: 'message-snapshot-1',
        recipientSnapshotId: 'recipient-snapshot-1',
        providerId: null,
        providerConnectionId: null,
        channel: DeliveryChannel::Email,
        idempotencyKey: str_repeat('a', 64),
        scheduledNotBeforeAt: $createdAt,
        priorityClass: DeliveryPriorityClass::Normal,
        state: $state,
        queueName: 'delivery.email.normal',
        queuePartitionKey: str_repeat('b', 64),
        backpressureReason: $reason,
        backpressuredAt: $backpressuredAt,
        version: 2,
        createdAt: $createdAt,
        updatedAt: $createdAt,
    );
}

function backpressureContext(string $workspaceId = 'workspace-a'): TenantContext
{
    return new TenantContext(
        organizationId: 'organization-1',
        workspaceId: $workspaceId,
        brandId: 'brand-1',
        actorId: 'actor-1',
    );
}

function backpressureDescriptorAt(string $instant): DescribeDeliveryBackpressure
{
    $clock = new class(new DateTimeImmutable($instant)) implements Clock
    {
        public function __construct(private DateTimeImmutable $instant) {}

        public function now(): DateTimeImmutable
        {
            return $this->instant;
        }
    };

    return new DescribeDeliveryBackpressure($clock);
}

it('exposes a machine-readable backpressure reason and age', function () {
    $operation = backpressureOperation(
        DeliveryOperationState::Backpressured,
        'quota_exhausted',
        new DateTimeImmutable('2026-09-08T14:59:00+00:00'),
    );

    $snapshot = backpressureDescriptorAt('2026-09-08T15:00:30+00:00')
        ->handle(backpressureContext(), $operation);

    expect($snapshot)->not->toBeNull()
        ->and($snapshot?->operationId)->toBe('operation-1')
        ->and($snapshot?->workspaceId)->toBe('workspace-a')
        ->and($snapshot?->reason)->toBe('quota_exhausted')
        ->and($snapshot?->ageSeconds)->toBe(90);
});

it('returns no backpressure snapshot for a non-backpressured operation', function () {
    $operation = backpressureOperation(DeliveryOperationState::Ready, null, null);

    expect(backpressureDescriptorAt('2026-09-08T15:00:00+00:00')->handle(backpressureContext(), $operation))
        ->toBeNull();
});

it('fails closed when persisted backpressure evidence is incomplete', function () {
    $operation = backpressureOperation(
        DeliveryOperationState::Backpressured,
        null,
        new DateTimeImmutable('2026-09-08T14:59:00+00:00'),
    );

    expect(fn () => backpressureDescriptorAt('2026-09-08T15:00:00+00:00')->handle(backpressureContext(), $operation))
        ->toThrow(LogicException::class, 'missing observable backpressure evidence');
});

it('denies cross-workspace backpressure inspection', function () {
    $operation = backpressureOperation(
        DeliveryOperationState::Backpressured,
        'quota_exhausted',
        new DateTimeImmutable('2026-09-08T14:59:00+00:00'),
        'workspace-a',
    );

    expect(fn () => backpressureDescriptorAt('2026-09-08T15:00:00+00:00')->handle(
        backpressureContext('workspace-b'),
        $operation,
    ))->toThrow(AuthorizationException::class);
});

it('never reports a negative age under clock skew', function () {
    $operation = backpressureOperation(
        DeliveryOperationState::Backpressured,
        'provider_connection_unavailable',
        new DateTimeImmutable('2026-09-08T15:01:00+00:00'),
    );

    $snapshot = backpressureDescriptorAt('2026-09-08T15:00:00+00:00')
        ->handle(backpressureContext(), $operation);

    expect($snapshot?->ageSeconds)->toBe(0);
});
