<?php

namespace App\Modules\Events\Application;

use App\Modules\Core\Domain\Contracts\Clock;
use App\Modules\Core\Domain\Contracts\IdentifierGenerator;
use App\Modules\Core\Domain\Contracts\OutboxRepository;
use App\Modules\Core\Domain\Messaging\OutboxMessage;
use App\Modules\Events\Domain\CanonicalEvent;
use DateTimeImmutable;

final readonly class CanonicalEventRecorder
{
    public function __construct(
        private Clock $clock,
        private IdentifierGenerator $identifiers,
        private OutboxRepository $outbox,
    ) {}

    public function record(
        string $eventType,
        string $workspaceId,
        array $subjects,
        string $aggregateType,
        string $aggregateId,
        array $payload,
        ?string $brandId = null,
        string $source = 'internal',
        ?string $sourceEventId = null,
        array $sourceMetadata = [],
        ?DateTimeImmutable $occurredAt = null,
        ?string $eventId = null,
    ): CanonicalEvent {
        $receivedAt = $this->clock->now();
        $event = new CanonicalEvent(
            eventId: $eventId ?? $this->identifiers->next(),
            eventType: $eventType,
            occurredAt: $occurredAt ?? $receivedAt,
            receivedAt: $receivedAt,
            workspaceId: $workspaceId,
            brandId: $brandId,
            subjects: $subjects,
            source: $source,
            sourceEventId: $sourceEventId,
            schemaVersion: CanonicalEvent::SCHEMA_VERSION,
            payload: $payload,
            sourceMetadata: $sourceMetadata,
        );

        $this->outbox->store(new OutboxMessage(
            id: $event->eventId,
            topic: $event->eventType,
            aggregateType: $aggregateType,
            aggregateId: $aggregateId,
            payload: $event->toArray(),
            headers: [
                'contract' => CanonicalEvent::OUTBOX_CONTRACT,
                'schema_version' => CanonicalEvent::SCHEMA_VERSION,
            ],
            occurredAt: $event->occurredAt,
            availableAt: $receivedAt,
        ));

        return $event;
    }
}
