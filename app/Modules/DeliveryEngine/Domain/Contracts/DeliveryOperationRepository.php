<?php

namespace App\Modules\DeliveryEngine\Domain\Contracts;

use App\Modules\DeliveryEngine\Domain\DeliveryOperation;
use App\Modules\DeliveryEngine\Domain\DeliveryOperationScheduleResult;
use DateTimeImmutable;

interface DeliveryOperationRepository
{
    public function schedule(
        string $id,
        string $workspaceId,
        string $messageSnapshotId,
        string $recipientSnapshotId,
        string $providerConnectionId,
        DateTimeImmutable $scheduledNotBeforeAt,
    ): DeliveryOperationScheduleResult;

    public function findByIdempotencyKey(string $workspaceId, string $idempotencyKey): ?DeliveryOperation;
}
