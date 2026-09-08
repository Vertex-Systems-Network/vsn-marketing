<?php

namespace App\Modules\DeliveryEngine\Domain\Contracts;

use App\Modules\DeliveryEngine\Domain\DeliveryOperation;
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
    ): DeliveryOperation;

    public function findByIdempotencyKey(string $workspaceId, string $idempotencyKey): ?DeliveryOperation;
}
