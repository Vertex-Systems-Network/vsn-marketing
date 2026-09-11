<?php

use App\Modules\Core\Domain\Contracts\Clock;
use App\Modules\DeliveryEngine\Application\DescribeDeliveryBackpressure;
use App\Modules\DeliveryEngine\Domain\DeliveryChannel;
use App\Modules\DeliveryEngine\Domain\DeliveryOperation;
use App\Modules\DeliveryEngine\Domain\DeliveryOperationState;
use App\Modules\DeliveryEngine\Domain\DeliveryPriorityClass;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;

function deliveryTelemetryOperation(
    string $workspaceId,
    string $reason,
    string $backpressuredAt,
    string $operationId = 'operation-hotspot-1',
    ?string $providerId = 'provider-hotspot-1',
    DeliveryChannel $channel = DeliveryChannel::Email,
): DeliveryOperation {
    $createdAt = new DateTimeImmutable('2026-09-12T00:00:00+00:00');

    return new DeliveryOperation(
        id: $operationId,
        workspaceId: $workspaceId,
        messageSnapshotId: 'message-snapshot-sensitive-1',
        recipientSnapshotId: 'recipient-snapshot-sensitive-1',
        providerId: $providerId,
        providerConnectionId: 'provider-connection-hotspot-1',
        channel: $channel,
        idempotencyKey: str_repeat('a', 64),
        scheduledNotBeforeAt: $createdAt,
        priorityClass: DeliveryPriorityClass::Normal,
        state: DeliveryOperationState::Backpressured,
        queueName: 'delivery.email.normal',
        queuePartitionKey: str_repeat('b', 64),
        backpressureReason: $reason,
        backpressuredAt: new DateTimeImmutable($backpressuredAt),
        version: 3,
        createdAt: $createdAt,
        updatedAt: $createdAt,
    );
}

function deliveryTelemetryContext(string $workspaceId): TenantContext
{
    return new TenantContext(
        organizationId: 'organization-hotspot',
        workspaceId: $workspaceId,
        brandId: 'brand-hotspot',
        actorId: 'actor-hotspot',
    );
}

function deliveryTelemetryDescriptor(string $instant): DescribeDeliveryBackpressure
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

it('emits bounded workspace provider channel and blocking evidence for hotspot diagnostics', function () {
    $snapshot = deliveryTelemetryDescriptor('2026-09-12T00:02:30+00:00')->handle(
        deliveryTelemetryContext('workspace-a'),
        deliveryTelemetryOperation(
            workspaceId: 'workspace-a',
            reason: 'quota_exhausted',
            backpressuredAt: '2026-09-12T00:00:30+00:00',
        ),
    );

    expect($snapshot)->not->toBeNull()
        ->and($snapshot?->operationId)->toBe('operation-hotspot-1')
        ->and($snapshot?->workspaceId)->toBe('workspace-a')
        ->and($snapshot?->providerId)->toBe('provider-hotspot-1')
        ->and($snapshot?->channel)->toBe('email')
        ->and($snapshot?->reason)->toBe('quota_exhausted')
        ->and($snapshot?->ageSeconds)->toBe(120)
        ->and(array_keys(get_object_vars($snapshot)))->toBe([
            'operationId',
            'workspaceId',
            'providerId',
            'channel',
            'reason',
            'backpressuredAt',
            'ageSeconds',
        ]);
});

it('preserves distinct blocking causes without exposing sensitive delivery dimensions', function (string $reason) {
    $snapshot = deliveryTelemetryDescriptor('2026-09-12T00:01:00+00:00')->handle(
        deliveryTelemetryContext('workspace-a'),
        deliveryTelemetryOperation('workspace-a', $reason, '2026-09-12T00:00:00+00:00'),
    );
    $payload = get_object_vars($snapshot);

    expect($snapshot?->reason)->toBe($reason)
        ->and($payload)->not->toHaveKeys([
            'messageSnapshotId',
            'recipientSnapshotId',
            'providerConnectionId',
            'idempotencyKey',
            'queuePartitionKey',
        ]);
})->with([
    'quota pressure' => 'quota_exhausted',
    'provider outage' => 'provider_connection_unavailable',
    'global capacity' => 'global_concurrency_exhausted',
    'workspace capacity' => 'workspace_concurrency_exhausted',
    'breaker hold' => 'circuit_breaker_open',
]);

it('keeps provider dimension nullable while retaining channel attribution', function () {
    $snapshot = deliveryTelemetryDescriptor('2026-09-12T00:01:00+00:00')->handle(
        deliveryTelemetryContext('workspace-a'),
        deliveryTelemetryOperation(
            workspaceId: 'workspace-a',
            reason: 'global_concurrency_exhausted',
            backpressuredAt: '2026-09-12T00:00:00+00:00',
            providerId: null,
        ),
    );

    expect($snapshot?->providerId)->toBeNull()
        ->and($snapshot?->channel)->toBe('email');
});

it('denies cross-workspace telemetry inspection', function () {
    $operation = deliveryTelemetryOperation(
        workspaceId: 'workspace-a',
        reason: 'quota_exhausted',
        backpressuredAt: '2026-09-12T00:00:00+00:00',
    );

    expect(fn () => deliveryTelemetryDescriptor('2026-09-12T00:01:00+00:00')->handle(
        deliveryTelemetryContext('workspace-b'),
        $operation,
    ))->toThrow(AuthorizationException::class, 'Delivery operation access denied.');
});

it('clamps clock-skewed queue age to zero for stable operational evidence', function () {
    $snapshot = deliveryTelemetryDescriptor('2026-09-12T00:00:00+00:00')->handle(
        deliveryTelemetryContext('workspace-a'),
        deliveryTelemetryOperation('workspace-a', 'quota_exhausted', '2026-09-12T00:01:00+00:00'),
    );

    expect($snapshot?->ageSeconds)->toBe(0);
});
