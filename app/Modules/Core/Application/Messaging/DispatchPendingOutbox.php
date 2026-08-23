<?php

namespace App\Modules\Core\Application\Messaging;

use App\Modules\Core\Domain\Contracts\DistributedLock;
use App\Modules\Core\Domain\Contracts\OutboxRepository;
use Illuminate\Console\Command;

final class DispatchPendingOutbox extends Command
{
    protected $signature = 'outbox:dispatch {--limit= : Maximum pending messages to enqueue}';

    protected $description = 'Enqueue pending transactional outbox messages for durable publication.';

    public function handle(OutboxRepository $outbox, DistributedLock $lock): int
    {
        $configuredLimit = (int) config('infrastructure.outbox.batch_size', 100);
        $limit = $this->option('limit') === null ? $configuredLimit : (int) $this->option('limit');

        if ($limit < 1 || $limit > 1000) {
            $this->error('The outbox dispatch limit must be between 1 and 1000.');

            return self::INVALID;
        }

        $dispatched = 0;
        $lockSeconds = max(1, (int) config('infrastructure.outbox.scan_lock_seconds', 55));

        $executed = $lock->run('vsn-marketing:outbox-dispatch', $lockSeconds, function () use ($outbox, $limit, &$dispatched): void {
            foreach ($outbox->pendingIds($limit) as $messageId) {
                PublishOutboxMessage::dispatch($messageId)
                    ->onConnection((string) config('queue.default'))
                    ->onQueue((string) config('infrastructure.outbox.queue', 'outbox'));

                $dispatched++;
            }
        });

        if (! $executed) {
            $this->components->info('Another outbox scanner owns the distributed lock.');

            return self::SUCCESS;
        }

        $this->components->info("Enqueued {$dispatched} pending outbox message(s).");

        return self::SUCCESS;
    }
}
