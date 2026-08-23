<?php

namespace App\Modules\Core\Domain\Messaging;

use DateTimeImmutable;

final readonly class OutboxMessage
{
    public function __construct(
        public string $id,
        public string $topic,
        public string $aggregateType,
        public string $aggregateId,
        public array $payload,
        public array $headers,
        public DateTimeImmutable $occurredAt,
        public DateTimeImmutable $availableAt,
    ) {
    }
}
