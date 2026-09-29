<?php

namespace App\Modules\Journeys\Application;

use Illuminate\Console\Command;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Cache;

/** Bounded scheduled recovery for durable journey work whose Redis wake-up was lost or consumed. */
final class RedispatchDueJourneyWorkCommand extends Command
{
    protected $signature = 'journeys:redispatch-due {--workspace-limit=100} {--work-limit=100}';

    protected $description = 'Redispatch due workspace-scoped journey work from durable PostgreSQL state.';

    private const CURSOR_KEY = 'journeys:redispatch-due:workspace-cursor';

    public function handle(DatabaseManager $database, RedispatchDueJourneyWork $redispatch): int
    {
        $workspaceLimit = (int) $this->option('workspace-limit');
        $workLimit = (int) $this->option('work-limit');
        if ($workspaceLimit < 1 || $workspaceLimit > 1000 || $workLimit < 1 || $workLimit > 1000) {
            $this->error('Journey redispatch limits must be between 1 and 1000.');

            return self::INVALID;
        }

        $lock = Cache::lock('vsn-marketing:journeys:redispatch-due', 55);
        if (! $lock->get()) {
            $this->components->info('Another journey recovery sweep is running.');

            return self::SUCCESS;
        }

        try {
            $cursor = Cache::get(self::CURSOR_KEY);
            $workspaces = $this->dueWorkspaces($database, $workspaceLimit, is_string($cursor) ? $cursor : null);
            if ($workspaces === [] && is_string($cursor)) {
                $workspaces = $this->dueWorkspaces($database, $workspaceLimit, null);
            }

            $dispatched = 0;
            foreach ($workspaces as $workspaceId) {
                $dispatched += $redispatch->handle((string) $workspaceId, $workLimit);
                Cache::put(self::CURSOR_KEY, (string) $workspaceId, now()->addDay());
            }

            $this->components->info("Redispatched {$dispatched} due journey work item(s) across ".count($workspaces).' workspace(s).');

            return self::SUCCESS;
        } finally {
            $lock->release();
        }
    }

    /** @return list<string> */
    private function dueWorkspaces(DatabaseManager $database, int $limit, ?string $after): array
    {
        $query = $database->table('journey_work_items')
            ->whereIn('status', ['pending', 'running', 'waiting'])
            ->where('available_at', '<=', now());
        if ($after !== null) {
            $query->where('workspace_id', '>', $after);
        }

        return $query->distinct()->orderBy('workspace_id')->limit($limit)->pluck('workspace_id')->map(fn ($id): string => (string) $id)->all();
    }
}
