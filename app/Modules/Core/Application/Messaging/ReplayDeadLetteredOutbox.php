<?php

namespace App\Modules\Core\Application\Messaging;

use App\Modules\Core\Domain\Contracts\OutboxRepository;
use Illuminate\Console\Command;

final class ReplayDeadLetteredOutbox extends Command
{
    protected $signature = 'outbox:replay {id : Dead-lettered outbox message UUID}';

    protected $description = 'Reset one terminal outbox failure to pending for deterministic replay.';

    public function handle(OutboxRepository $outbox): int
    {
        $id = (string) $this->argument('id');

        if (! $outbox->replayDeadLetter($id)) {
            $this->error('The outbox message is not currently dead-lettered.');

            return self::FAILURE;
        }

        $this->components->info("Outbox message {$id} is pending for replay.");

        return self::SUCCESS;
    }
}
