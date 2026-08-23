<?php

namespace App\Modules\Core\Infrastructure\Outbox;

use App\Modules\Core\Domain\Contracts\TransactionalOutbox;
use App\Modules\Core\Domain\ValueObjects\OutboxEnvelope;
use Illuminate\Database\DatabaseManager;
use LogicException;

final readonly class DatabaseTransactionalOutbox implements TransactionalOutbox
{
    public function __construct(private DatabaseManager $database) {}
    public function record(OutboxEnvelope $message): void
    {
        $connection = $this->database->connection();
        if ($connection->transactionLevel() < 1) {
            throw new LogicException('Transactional outbox writes require an active database transaction.');
        }
        $connection->table('outbox_messages')->insert([
            'id' => $message->id, 'topic' => $message->topic,
            'aggregate_type' => $message->aggregateType, 'aggregate_id' => $message->aggregateId,
            'idempotency_key' => $message->idempotencyKey,
            'payload' => json_encode($message->payload, JSON_THROW_ON_ERROR),
            'headers' => json_encode($message->headers, JSON_THROW_ON_ERROR),
            'occurred_at' => $message->occurredAt, 'available_at' => $message->occurredAt,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
