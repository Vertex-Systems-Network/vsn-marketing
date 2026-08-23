<?php

namespace App\Modules\Core\Infrastructure\Outbox;

use Illuminate\Console\Command;

final class RelayOutboxCommand extends Command
{
    protected $signature = 'outbox:relay';
    protected $description = 'Claim pending transactional outbox messages and hand them to the Redis queue.';
    public function handle(OutboxRelay $relay): int
    {
        $count = $relay->relay();
        $this->components->info("Relayed {$count} outbox message(s).");
        return self::SUCCESS;
    }
}
