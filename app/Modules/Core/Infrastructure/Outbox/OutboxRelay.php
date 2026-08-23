<?php

namespace App\Modules\Core\Infrastructure\Outbox;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Throwable;

final readonly class OutboxRelay
{
    public function __construct(private DatabaseManager $database, private Dispatcher $bus) {}
    public function relay(): int
    {
        $connection = $this->database->connection();
        $now = now();
        $staleBefore = $now->copy()->subSeconds((int) config('infrastructure.outbox.lock_seconds', 120));
        $batchSize = max(1, (int) config('infrastructure.outbox.batch_size', 100));
        $maxRelayAttempts = max(1, (int) config('infrastructure.outbox.max_relay_attempts', 10));
        $ids = $connection->transaction(function () use ($connection, $now, $staleBefore, $batchSize, $maxRelayAttempts): array {
            $rows = $connection->table('outbox_messages')->select('id')
                ->whereNull('published_at')->where('available_at', '<=', $now)
                ->where('relay_attempts', '<', $maxRelayAttempts)
                ->where(function ($query) use ($staleBefore): void {
                    $query->whereNull('locked_at')->orWhere('locked_at', '<=', $staleBefore);
                })->orderBy('available_at')->limit($batchSize)->lock('FOR UPDATE SKIP LOCKED')->get();
            $ids = $rows->pluck('id')->map(static fn ($id): string => (string) $id)->all();
            if ($ids !== []) {
                $connection->table('outbox_messages')->whereIn('id', $ids)->update([
                    'locked_at' => $now, 'relay_attempts' => $connection->raw('relay_attempts + 1'),
                    'last_error' => null, 'updated_at' => $now,
                ]);
            }
            return $ids;
        });
        foreach ($ids as $id) {
            try {
                $this->bus->dispatch(new ProcessOutboxMessage($id));
            } catch (Throwable $exception) {
                $connection->table('outbox_messages')->where('id', $id)->whereNull('published_at')->update([
                    'locked_at' => null, 'last_error' => mb_substr($exception->getMessage(), 0, 4000), 'updated_at' => now(),
                ]);
                throw $exception;
            }
        }
        return count($ids);
    }
}
