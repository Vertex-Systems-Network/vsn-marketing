<?php

namespace App\Modules\Journeys\Application;

use Illuminate\Bus\Queueable;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Contracts\Queue\ShouldQueue;

/** Redis wake-up only: every durable identity and decision is reloaded from PostgreSQL. */
final class JourneyNodeJob implements ShouldQueue
{
    use Queueable;
    use Dispatchable;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(public string $workspaceId, public string $workItemId)
    {
        $this->onConnection('redis')->onQueue('journeys')->afterCommit();
    }

    public function handle(ProcessJourneyNode $processor): void
    {
        $processor->handle($this->workspaceId, $this->workItemId);
    }
}
