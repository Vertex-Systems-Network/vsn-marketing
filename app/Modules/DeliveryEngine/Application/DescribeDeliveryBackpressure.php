<?php

namespace App\Modules\DeliveryEngine\Application;

use App\Modules\Core\Domain\Contracts\Clock;
use App\Modules\DeliveryEngine\Domain\DeliveryBackpressureSnapshot;
use App\Modules\DeliveryEngine\Domain\DeliveryOperation;
use App\Modules\DeliveryEngine\Domain\DeliveryOperationState;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use LogicException;

final readonly class DescribeDeliveryBackpressure
{
    public function __construct(private Clock $clock) {}

    public function handle(TenantContext $context, DeliveryOperation $operation): ?DeliveryBackpressureSnapshot
    {
        if ($operation->workspaceId !== $context->workspaceId) {
            throw new AuthorizationException('Delivery operation access denied.');
        }

        if ($operation->state !== DeliveryOperationState::Backpressured) {
            return null;
        }

        if ($operation->backpressureReason === null || $operation->backpressuredAt === null) {
            throw new LogicException('Backpressured delivery operation is missing observable backpressure evidence.');
        }

        $ageSeconds = max(0, $this->clock->now()->getTimestamp() - $operation->backpressuredAt->getTimestamp());

        return new DeliveryBackpressureSnapshot(
            operationId: $operation->id,
            workspaceId: $operation->workspaceId,
            providerId: $operation->providerId,
            channel: $operation->channel->value,
            reason: $operation->backpressureReason,
            backpressuredAt: $operation->backpressuredAt,
            ageSeconds: $ageSeconds,
        );
    }
}
