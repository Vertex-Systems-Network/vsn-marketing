<?php

namespace App\Modules\DeliveryEngine\Domain\Contracts;

use App\Modules\DeliveryEngine\Domain\DeliveryOperationCreation;
use App\Modules\DeliveryEngine\Domain\DeliveryPriorityClass;
use App\Modules\DeliveryEngine\Domain\DeliveryQueueRoute;
use App\Modules\DeliveryEngine\Domain\ExecutionSnapshots;
use DateTimeImmutable;

interface DeliveryOperationRepository
{
    public function findExecutionSnapshots(
        string $workspaceId,
        ?string $brandScopeId,
        string $messageSnapshotId,
        string $recipientSnapshotId,
    ): ?ExecutionSnapshots;

    public function createOrFind(
        string $id,
        ExecutionSnapshots $snapshots,
        string $idempotencyKey,
        DateTimeImmutable $scheduledNotBeforeAt,
        DeliveryPriorityClass $priorityClass,
        DeliveryQueueRoute $route,
    ): DeliveryOperationCreation;
}
