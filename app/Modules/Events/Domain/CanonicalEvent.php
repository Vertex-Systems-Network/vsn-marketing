<?php

namespace App\Modules\Events\Domain;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class CanonicalEvent
{
    public const int SCHEMA_VERSION = 1;

    public const string OUTBOX_CONTRACT = 'vsn.canonical-event';

    public function __construct(
        public string $eventId,
        public string $eventType,
        public DateTimeImmutable $occurredAt,
        public DateTimeImmutable $receivedAt,
        public string $workspaceId,
        public ?string $brandId,
        public array $subjects,
        public string $source,
        public ?string $sourceEventId,
        public int $schemaVersion,
        public array $payload,
        public array $sourceMetadata,
    ) {
        if ($this->schemaVersion !== self::SCHEMA_VERSION) {
            throw new InvalidArgumentException("Unsupported canonical event schema version: {$this->schemaVersion}");
        }

        if ($this->eventId === '' || $this->workspaceId === '' || $this->source === '') {
            throw new InvalidArgumentException('Canonical event identifiers and source must not be empty.');
        }

        if (! preg_match('/^[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)+$/', $this->eventType)) {
            throw new InvalidArgumentException("Invalid canonical event type: {$this->eventType}");
        }

        if ($this->brandId !== null && $this->brandId === '') {
            throw new InvalidArgumentException('brand_id must be null or a non-empty identifier.');
        }

        foreach ($this->subjects as $name => $identifier) {
            if (! is_string($name) || $name === '' || ! is_string($identifier) || $identifier === '') {
                throw new InvalidArgumentException('Canonical event subjects must be non-empty string identifier pairs.');
            }
        }

        self::assertSanitizedMetadata($this->sourceMetadata);
    }

    public function toArray(): array
    {
        return [
            'event_id' => $this->eventId,
            'event_type' => $this->eventType,
            'occurred_at' => $this->occurredAt->format(DATE_ATOM),
            'received_at' => $this->receivedAt->format(DATE_ATOM),
            'workspace_id' => $this->workspaceId,
            'brand_id' => $this->brandId,
            'subjects' => $this->subjects,
            'source' => $this->source,
            'source_event_id' => $this->sourceEventId,
            'schema_version' => $this->schemaVersion,
            'payload' => $this->payload,
            'source_metadata' => $this->sourceMetadata,
        ];
    }

    public static function fromArray(array $data): self
    {
        foreach (['event_id', 'event_type', 'occurred_at', 'received_at', 'workspace_id', 'subjects', 'source', 'schema_version', 'payload', 'source_metadata'] as $required) {
            if (! array_key_exists($required, $data)) {
                throw new InvalidArgumentException("Canonical event envelope is missing required field: {$required}");
            }
        }

        return new self(
            eventId: (string) $data['event_id'],
            eventType: (string) $data['event_type'],
            occurredAt: new DateTimeImmutable((string) $data['occurred_at']),
            receivedAt: new DateTimeImmutable((string) $data['received_at']),
            workspaceId: (string) $data['workspace_id'],
            brandId: isset($data['brand_id']) ? (string) $data['brand_id'] : null,
            subjects: is_array($data['subjects']) ? $data['subjects'] : [],
            source: (string) $data['source'],
            sourceEventId: isset($data['source_event_id']) ? (string) $data['source_event_id'] : null,
            schemaVersion: (int) $data['schema_version'],
            payload: is_array($data['payload']) ? $data['payload'] : [],
            sourceMetadata: is_array($data['source_metadata']) ? $data['source_metadata'] : [],
        );
    }

    private static function assertSanitizedMetadata(array $metadata): void
    {
        foreach ($metadata as $key => $value) {
            if (is_string($key) && preg_match('/password|secret|token|authorization|credential|api[_-]?key|private[_-]?key/i', $key)) {
                throw new InvalidArgumentException("Sensitive source metadata key is forbidden: {$key}");
            }

            if (is_array($value)) {
                self::assertSanitizedMetadata($value);
            }
        }
    }
}
