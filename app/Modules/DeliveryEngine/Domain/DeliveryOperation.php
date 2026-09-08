<?php

namespace App\Modules\DeliveryEngine\Domain;

use DateTimeImmutable;

final readonly class DeliveryOperation
{
    public function __construct(
        public string $id,
        public string $workspaceId,
        public string $messageSnapshotId,
        public string $recipientSnapshotId,
        public string $providerConnectionId,
        public DeliveryChannel $channel,
        public string $idempotencyKey,
        public DateTimeImmutable $scheduledNotBeforeAt,
        public DeliveryPriorityClass $priorityClass,
        public DeliveryOperationState $state,
        public int $version,
        public ?string $backpressureReason = null,
        public ?DateTimeImmutable $backpressuredAt = null,
    ) {}
}
