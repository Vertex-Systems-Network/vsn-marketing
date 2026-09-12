<?php

namespace App\Modules\DeliveryEngine\Domain;

use DateTimeImmutable;

final readonly class DeliveryBackpressureSnapshot
{
    public function __construct(
        public string $operationId,
        public string $workspaceId,
        public ?string $providerId,
        public string $channel,
        public string $reason,
        public DateTimeImmutable $backpressuredAt,
        public int $ageSeconds,
    ) {}
}
