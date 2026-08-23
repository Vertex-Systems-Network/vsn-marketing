<?php

namespace App\Modules\Core\Application\Events;

final readonly class OutboxMessageReady
{
    public function __construct(
        public string $id, public string $topic, public string $idempotencyKey,
        public array $payload, public array $headers,
        public ?string $aggregateType, public ?string $aggregateId,
    ) {}
}
