<?php

namespace App\Modules\Core\Application\Messaging;

use App\Modules\Core\Domain\Contracts\Clock;
use App\Modules\Core\Domain\Contracts\IdentifierGenerator;
use App\Modules\Core\Domain\Contracts\OutboxRepository;
use App\Modules\Core\Domain\Messaging\OutboxMessage;
use DateTimeImmutable;

final readonly class OutboxRecorder
{
    public function __construct(
        private Clock $clock,
        private IdentifierGenerator $identifiers,
        private OutboxRepository $outbox,
    ) {}

    public function record(
        string $topic,
        string $aggregateType,
        string $aggregateId,
        array $payload,
        array $headers = [],
        ?DateTimeImmutable $availableAt = null,
        ?string $id = null,
    ): string {
        $occurredAt = $this->clock->now();
        $id ??= $this->identifiers->next();

        $this->outbox->store(new OutboxMessage(
            id: $id,
            topic: $topic,
            aggregateType: $aggregateType,
            aggregateId: $aggregateId,
            payload: $payload,
            headers: $headers,
            occurredAt: $occurredAt,
            availableAt: $availableAt ?? $occurredAt,
        ));

        return $id;
    }
}
