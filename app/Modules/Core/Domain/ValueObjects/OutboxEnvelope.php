<?php

namespace App\Modules\Core\Domain\ValueObjects;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class OutboxEnvelope
{
    public function __construct(
        public string $id,
        public string $topic,
        public string $idempotencyKey,
        public array $payload,
        public DateTimeImmutable $occurredAt,
        public array $headers = [],
        public ?string $aggregateType = null,
        public ?string $aggregateId = null,
    ) {
        foreach (['id' => $id, 'topic' => $topic, 'idempotencyKey' => $idempotencyKey] as $field => $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException("{$field} must not be empty.");
            }
        }
    }
}
