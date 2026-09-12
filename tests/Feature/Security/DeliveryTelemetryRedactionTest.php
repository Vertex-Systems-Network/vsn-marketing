<?php

use App\Modules\Core\Domain\Contracts\Clock;
use App\Modules\DeliveryEngine\Application\DescribeDeliveryBackpressure;
use App\Modules\DeliveryEngine\Domain\DeliveryChannel;
use App\Modules\DeliveryEngine\Domain\DeliveryOperation;
use App\Modules\DeliveryEngine\Domain\DeliveryOperationState;
use App\Modules\DeliveryEngine\Domain\DeliveryPriorityClass;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;

function deliveryTelemetryRedactionOperation(string $workspaceId = 'workspace-a'): DeliveryOperation
{
    $createdAt = new DateTimeImmutable('2026-09-12T00:00:00+00:00');

    return new DeliveryOperation(
        id: 'operation-public-id',
        workspaceId: $workspaceId,
        messageSnapshotId: 'message-secret-payload-marker',
        recipientSnapshotId: 'recipient-secret-address-marker',
        providerId: 'provider-public-id',
        providerConnectionId: 'connection-secret-marker',
        channel: DeliveryChannel::Email,
        idempotencyKey: hash('sha256', 'secret-idempotency-material'),
        scheduledNotBeforeAt: $createdAt,
        priorityClass: DeliveryPriorityClass::Normal,
        state: DeliveryOperationState::Backpressured,
        queueName: 'delivery.email.normal',
        queuePartitionKey: hash('sha256', 'recipient@example.test'),
        backpressureReason: 'provider_connection_unavailable',
        backpressuredAt: $createdAt,
        version: 2,
        createdAt: $createdAt,
        updatedAt: $createdAt,
    );
}

function deliveryTelemetryRedactionContext(string $workspaceId): TenantContext
{
    return new TenantContext(
        organizationId: 'organization-redaction',
        workspaceId: $workspaceId,
        brandId: 'brand-redaction',
        actorId: 'actor-redaction',
    );
}

function deliveryTelemetryRedactionDescriptor(): DescribeDeliveryBackpressure
{
    $clock = new class implements Clock
    {
        public function now(): DateTimeImmutable
        {
            return new DateTimeImmutable('2026-09-12T00:01:00+00:00');
        }
    };

    return new DescribeDeliveryBackpressure($clock);
}

it('retains only bounded operational dimensions and redacts sensitive delivery material', function () {
    $snapshot = deliveryTelemetryRedactionDescriptor()->handle(
        deliveryTelemetryRedactionContext('workspace-a'),
        deliveryTelemetryRedactionOperation(),
    );
    $encoded = json_encode($snapshot, JSON_THROW_ON_ERROR);

    expect(array_keys(get_object_vars($snapshot)))->toBe([
        'operationId',
        'workspaceId',
        'providerId',
        'channel',
        'reason',
        'backpressuredAt',
        'ageSeconds',
    ])
        ->and($snapshot?->providerId)->toBe('provider-public-id')
        ->and($snapshot?->channel)->toBe('email')
        ->and($encoded)->toContain('provider_connection_unavailable')
        ->and($encoded)->not->toContain('message-secret-payload-marker')
        ->and($encoded)->not->toContain('recipient-secret-address-marker')
        ->and($encoded)->not->toContain('connection-secret-marker')
        ->and($encoded)->not->toContain('secret-idempotency-material')
        ->and($encoded)->not->toContain('recipient@example.test');
});

it('exposes normalized blocking reason rather than secret-bearing provider material', function () {
    $snapshot = deliveryTelemetryRedactionDescriptor()->handle(
        deliveryTelemetryRedactionContext('workspace-a'),
        deliveryTelemetryRedactionOperation(),
    );

    expect($snapshot?->reason)->toBe('provider_connection_unavailable')
        ->and($snapshot?->reason)->not->toContain('secret')
        ->and($snapshot?->reason)->not->toContain('token')
        ->and($snapshot?->reason)->not->toContain('recipient');
});

it('rejects cross-workspace telemetry access before emitting evidence', function () {
    expect(fn () => deliveryTelemetryRedactionDescriptor()->handle(
        deliveryTelemetryRedactionContext('workspace-b'),
        deliveryTelemetryRedactionOperation('workspace-a'),
    ))->toThrow(AuthorizationException::class, 'Delivery operation access denied.');
});
