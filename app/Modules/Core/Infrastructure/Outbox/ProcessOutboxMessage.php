<?php

namespace App\Modules\Core\Infrastructure\Outbox;

use App\Modules\Core\Application\Events\OutboxMessageReady;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Event;
use Throwable;

final class ProcessOutboxMessage implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    public int $tries = 5;
    public int $timeout = 60;
    public int $uniqueFor = 300;
    public function __construct(public readonly string $outboxId)
    {
        $this->onConnection('redis');
        $this->onQueue((string) config('infrastructure.outbox.queue', 'outbox'));
    }
    public function uniqueId(): string { return $this->outboxId; }
    public function backoff(): array { return array_map('intval', (array) config('infrastructure.outbox.backoff', [5, 30, 120, 300])); }
    public function handle(DatabaseManager $database): void
    {
        $connection = $database->connection();
        $message = $connection->table('outbox_messages')->where('id', $this->outboxId)->first();
        if ($message === null || $message->published_at !== null) return;
        Event::dispatch(new OutboxMessageReady(
            id: (string) $message->id, topic: (string) $message->topic,
            idempotencyKey: (string) $message->idempotency_key,
            payload: (array) json_decode((string) $message->payload, true, flags: JSON_THROW_ON_ERROR),
            headers: (array) json_decode((string) $message->headers, true, flags: JSON_THROW_ON_ERROR),
            aggregateType: $message->aggregate_type === null ? null : (string) $message->aggregate_type,
            aggregateId: $message->aggregate_id === null ? null : (string) $message->aggregate_id,
        ));
        $connection->table('outbox_messages')->where('id', $this->outboxId)->whereNull('published_at')->update([
            'published_at' => now(), 'locked_at' => null, 'last_error' => null, 'updated_at' => now(),
        ]);
    }
    public function failed(?Throwable $exception): void
    {
        app(DatabaseManager::class)->connection()->table('outbox_messages')
            ->where('id', $this->outboxId)->whereNull('published_at')->update([
                'locked_at' => null,
                'available_at' => now()->addSeconds((int) config('infrastructure.outbox.failure_retry_seconds', 900)),
                'last_error' => mb_substr($exception?->getMessage() ?? 'Queue job failed without an exception message.', 0, 4000),
                'updated_at' => now(),
            ]);
    }
}
