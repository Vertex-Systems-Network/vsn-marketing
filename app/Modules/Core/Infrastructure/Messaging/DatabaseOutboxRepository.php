<?php

namespace App\Modules\Core\Infrastructure\Messaging;

use App\Modules\Core\Domain\Contracts\Clock;
use App\Modules\Core\Domain\Contracts\OutboxRepository;
use App\Modules\Core\Domain\Messaging\OutboxMessage;
use DateTimeImmutable;
use Illuminate\Database\DatabaseManager;
use JsonException;

final readonly class DatabaseOutboxRepository implements OutboxRepository
{
    public function __construct(
        private DatabaseManager $database,
        private Clock $clock,
    ) {
    }

    public function store(OutboxMessage $message): void
    {
        $this->database->connection()->table('outbox_messages')->insert([
            'id' => $message->id,
            'topic' => $message->topic,
            'aggregate_type' => $message->aggregateType,
            'aggregate_id' => $message->aggregateId,
            'payload' => json_encode($message->payload, JSON_THROW_ON_ERROR),
            'headers' => json_encode($message->headers, JSON_THROW_ON_ERROR),
            'occurred_at' => $message->occurredAt,
            'available_at' => $message->availableAt,
            'published_at' => null,
            'attempts' => 0,
            'last_error' => null,
            'created_at' => $message->occurredAt,
            'updated_at' => $message->occurredAt,
        ]);
    }

    public function findPending(string $id): ?OutboxMessage
    {
        $row = $this->database->connection()
            ->table('outbox_messages')
            ->where('id', $id)
            ->whereNull('published_at')
            ->first();

        return $row === null ? null : $this->hydrate($row);
    }

    public function pendingIds(int $limit): array
    {
        return $this->database->connection()
            ->table('outbox_messages')
            ->whereNull('published_at')
            ->where('available_at', '<=', $this->clock->now())
            ->orderBy('occurred_at')
            ->limit($limit)
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();
    }

    public function markPublished(string $id): void
    {
        $now = $this->clock->now();

        $this->database->connection()
            ->table('outbox_messages')
            ->where('id', $id)
            ->whereNull('published_at')
            ->update([
                'published_at' => $now,
                'last_error' => null,
                'updated_at' => $now,
            ]);
    }

    public function markAttemptFailed(string $id, string $error): void
    {
        $this->database->connection()
            ->table('outbox_messages')
            ->where('id', $id)
            ->whereNull('published_at')
            ->increment('attempts', 1, [
                'last_error' => mb_substr($error, 0, 2000),
                'updated_at' => $this->clock->now(),
            ]);
    }

    /**
     * @throws JsonException
     */
    private function hydrate(object $row): OutboxMessage
    {
        return new OutboxMessage(
            id: (string) $row->id,
            topic: (string) $row->topic,
            aggregateType: (string) $row->aggregate_type,
            aggregateId: (string) $row->aggregate_id,
            payload: json_decode((string) $row->payload, true, 512, JSON_THROW_ON_ERROR),
            headers: json_decode((string) $row->headers, true, 512, JSON_THROW_ON_ERROR),
            occurredAt: new DateTimeImmutable((string) $row->occurred_at),
            availableAt: new DateTimeImmutable((string) $row->available_at),
        );
    }
}
