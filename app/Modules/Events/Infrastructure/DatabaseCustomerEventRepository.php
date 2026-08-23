<?php

namespace App\Modules\Events\Infrastructure;

use App\Modules\Events\Domain\CanonicalEvent;
use App\Modules\Events\Domain\Contracts\CustomerEventRepository;
use App\Modules\Events\Domain\CustomerEventSubject;
use App\Modules\Events\Domain\EventType;
use DateTimeImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use InvalidArgumentException;
use JsonException;
use RuntimeException;
use stdClass;

final readonly class DatabaseCustomerEventRepository implements CustomerEventRepository
{
    public function __construct(private DatabaseManager $database) {}

    public function store(EventType $eventType, CanonicalEvent $event, CustomerEventSubject $subject): bool
    {
        $connection = $this->database->connection();
        $existing = $this->findRow($event->eventId);
        if ($existing instanceof stdClass) {
            $this->assertMatchingExisting($existing, $event, $subject);

            return false;
        }

        $inserted = $connection->table('customer_events')->insertOrIgnore([
            'id' => $event->eventId,
            'workspace_id' => $event->workspaceId,
            'brand_id' => $event->brandId,
            'event_type_id' => $eventType->id,
            'contact_id' => $subject->contactId,
            'contact_identity_id' => $subject->contactIdentityId,
            'occurred_at' => $event->occurredAt,
            'received_at' => $event->receivedAt,
            'source' => $event->source,
            'source_event_id' => $event->sourceEventId,
            'schema_version' => $event->schemaVersion,
            'subjects' => json_encode($event->subjects, JSON_THROW_ON_ERROR),
            'payload' => json_encode($event->payload, JSON_THROW_ON_ERROR),
            'source_metadata' => json_encode($event->sourceMetadata, JSON_THROW_ON_ERROR),
            'created_at' => $event->receivedAt,
        ]);

        if ($inserted === 1) {
            return true;
        }

        $existing = $this->findRow($event->eventId);
        if (! $existing instanceof stdClass) {
            throw new RuntimeException('Canonical customer event insert was ignored without an existing event.');
        }
        $this->assertMatchingExisting($existing, $event, $subject);

        return false;
    }

    public function timeline(
        string $workspaceId,
        ?string $brandScopeId,
        string $contactId,
        int $limit,
    ): array {
        $query = $this->baseQuery()
            ->where('customer_events.workspace_id', $workspaceId)
            ->where('customer_events.contact_id', $contactId);

        if ($brandScopeId !== null) {
            $query->where('customer_events.brand_id', $brandScopeId);
        }

        return $query
            ->orderByDesc('customer_events.occurred_at')
            ->orderByDesc('customer_events.received_at')
            ->orderByDesc('customer_events.id')
            ->limit($limit)
            ->get()
            ->map(fn (stdClass $row): CanonicalEvent => $this->hydrateEvent($row))
            ->all();
    }

    private function findRow(string $eventId): ?stdClass
    {
        $row = $this->baseQuery()->where('customer_events.id', $eventId)->first();

        return $row instanceof stdClass ? $row : null;
    }

    private function baseQuery(): Builder
    {
        return $this->database->connection()->table('customer_events')
            ->join('event_types', function ($join): void {
                $join->on('event_types.id', '=', 'customer_events.event_type_id')
                    ->on('event_types.workspace_id', '=', 'customer_events.workspace_id');
            })
            ->select([
                'customer_events.*',
                'event_types.canonical_name as event_type_name',
            ]);
    }

    private function assertMatchingExisting(
        stdClass $row,
        CanonicalEvent $event,
        CustomerEventSubject $subject,
    ): void {
        if ((string) $row->workspace_id !== $event->workspaceId) {
            throw new AuthorizationException('Canonical event identity belongs to another workspace.');
        }

        $stored = $this->hydrateEvent($row);
        $storedIdentityId = $row->contact_identity_id === null ? null : (string) $row->contact_identity_id;
        if (
            $stored->toArray() !== $event->toArray()
            || (string) $row->contact_id !== $subject->contactId
            || $storedIdentityId !== $subject->contactIdentityId
        ) {
            throw new InvalidArgumentException('Canonical event identity conflicts with persisted event data.');
        }
    }

    private function hydrateEvent(stdClass $row): CanonicalEvent
    {
        return new CanonicalEvent(
            eventId: (string) $row->id,
            eventType: (string) $row->event_type_name,
            occurredAt: new DateTimeImmutable((string) $row->occurred_at),
            receivedAt: new DateTimeImmutable((string) $row->received_at),
            workspaceId: (string) $row->workspace_id,
            brandId: $row->brand_id === null ? null : (string) $row->brand_id,
            subjects: $this->decodeArray($row->subjects),
            source: (string) $row->source,
            sourceEventId: $row->source_event_id === null ? null : (string) $row->source_event_id,
            schemaVersion: (int) $row->schema_version,
            payload: $this->decodeArray($row->payload),
            sourceMetadata: $this->decodeArray($row->source_metadata),
        );
    }

    /** @return array<array-key, mixed> */
    private function decodeArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (! is_string($value)) {
            return [];
        }

        try {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }
}
