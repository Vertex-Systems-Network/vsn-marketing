<?php

namespace App\Modules\DeliveryEngine\Domain;

final readonly class DeliveryQueueRoute
{
    public function __construct(
        public string $queueName,
        public string $partitionKey,
    ) {}

    public static function for(
        string $workspaceId,
        DeliveryChannel $channel,
        DeliveryPriorityClass $priority,
    ): self {
        return new self(
            queueName: sprintf('delivery.%s.%s', $channel->value, $priority->value),
            partitionKey: hash('sha256', implode('|', [$workspaceId, $channel->value])),
        );
    }
}
