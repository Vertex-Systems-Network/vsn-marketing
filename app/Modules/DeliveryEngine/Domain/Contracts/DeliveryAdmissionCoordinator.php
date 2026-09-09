<?php

namespace App\Modules\DeliveryEngine\Domain\Contracts;

interface DeliveryAdmissionCoordinator
{
    public function tryAcquire(
        string $workspaceId,
        string $operationId,
        int $workspaceConcurrencyLimit,
        int $globalConcurrencyLimit,
        int $ttlSeconds,
    ): bool;

    public function release(string $workspaceId, string $operationId): void;
}
