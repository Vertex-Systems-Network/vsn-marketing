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
            'dead_lettered_at' => null,
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
            ->whereNull('dead_lettered_at')
            ->where('available_at', '<=', $this->clock->now())
            ->first();

        return $row === null ? null : $this->hydrate($row);
    }

    public function pendingIds(int $limit): array
    {
        return $this->database->connection()
            ->table('outbox_messages')
            ->whereNull('published_at')
            ->whereNull('dead_lettered_at')
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
            ->whereNull('dead_lettered_at')
            ->update([
                'published_at' => $now,
                'last_error' => null,
                'updated_at' => $now,
            ]);
    }

    public function markAttemptFailed(string $id, string $error, int $maxAttempts, array $backoffSeconds): void
    {
        $this->database->connection()->transaction(function () use ($id, $error, $maxAttempts, $backoffSeconds): void {
            $query = $this->database->connection()
                ->table('outbox_messages')
                ->where('id', $id)
                ->whereNull('published_at')
                ->whereNull('dead_lettered_at');
            $row = $query->lockForUpdate()->first();

            if ($row === null) {
                return;
            }

            $now = $this->clock->now();
            $attempts = (int) $row->attempts + 1;
            $terminal = $attempts >= max(1, $maxAttempts);
            $delayIndex = max(0, min($attempts - 1, count($backoffSeconds) - 1));
            $delay = $terminal || $backoffSeconds === [] ? 0 : max(0, (int) $backoffSeconds[$delayIndex]);
            $availableAt = $delay === 0 ? $now : ($now->modify("+{$delay} seconds") ?: $now);

            $query->update([
                'attempts' => $attempts,
                'last_error' => mb_substr($error, 0, 2000),
                'available_at' => $availableAt,
                'dead_lettered_at' => $terminal ? $now : null,
                'updated_at' => $now,
            ]);
        });
    }

    public function replayDeadLetter(string $id): bool
    {
        $now = $this->clock->now();
        $updated = $this->database->connection()
            ->table('outbox_messages')
            ->where('id', $id)
            ->whereNull('published_at')
            ->whereNotNull('dead_lettered_at')
            ->update([
                'attempts' => 0,
                'last_error' => null,
                'available_at' => $now,
                'dead_lettered_at' => null,
                'updated_at' => $now,
            ]);

        return $updated === 1;
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
