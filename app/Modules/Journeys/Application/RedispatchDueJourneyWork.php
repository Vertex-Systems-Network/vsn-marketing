<?php

namespace App\Modules\Journeys\Application;

use App\Modules\Journeys\Domain\JourneyDefinitionException;
use Illuminate\Database\DatabaseManager;

/** Bounded recovery sweep; Redis messages can be redelivered without duplicating node effects. */
final readonly class RedispatchDueJourneyWork
{
    public function __construct(private DatabaseManager $database) {}

    public function handle(string $workspaceId, int $limit = 100): int
    {
        if ($workspaceId === '' || $limit < 1 || $limit > 1000) {
            throw new JourneyDefinitionException('invalid_recovery_scope_or_limit', '$.recovery');
        }
        $items = $this->database->table('journey_work_items')
            ->where('workspace_id', $workspaceId)
            ->whereIn('status', ['pending', 'running', 'waiting'])
            ->where('available_at', '<=', now())
            ->orderBy('available_at')->orderBy('id')->limit($limit)->get();
        foreach ($items as $item) {
            JourneyNodeJob::dispatch($workspaceId, (string) $item->id);
        }

        return $items->count();
    }
}
