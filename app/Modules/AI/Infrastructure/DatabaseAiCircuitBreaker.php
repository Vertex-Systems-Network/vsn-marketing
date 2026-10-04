<?php

namespace App\Modules\AI\Infrastructure;

use App\Modules\AI\Domain\Contracts\AiCircuitBreaker;
use Illuminate\Support\Facades\DB;

/** Explicit route/workspace rows opt into this circuit; unknown rows deny. */
final class DatabaseAiCircuitBreaker implements AiCircuitBreaker
{
    public function allows(string $workspaceId, string $routeId): bool
    {
        $row = DB::table('ai_route_circuits')
            ->where('workspace_id', $workspaceId)->where('route_id', $routeId)->first();

        return $row !== null && ($row->open_until === null || strtotime((string) $row->open_until) <= time());
    }

    public function succeeded(string $workspaceId, string $routeId): void
    {
        DB::table('ai_route_circuits')->where('workspace_id', $workspaceId)->where('route_id', $routeId)
            ->update(['failure_count' => 0, 'open_until' => null, 'updated_at' => now()]);
    }

    public function failed(string $workspaceId, string $routeId): void
    {
        DB::transaction(function () use ($workspaceId, $routeId): void {
            $row = DB::table('ai_route_circuits')
                ->where('workspace_id', $workspaceId)->where('route_id', $routeId)
                ->lockForUpdate()->first();
            if ($row === null) {
                return;
            }

            $failures = min((int) $row->failure_count + 1, 3);
            DB::table('ai_route_circuits')->where('workspace_id', $workspaceId)->where('route_id', $routeId)
                ->update([
                    'failure_count' => $failures,
                    'open_until' => $failures >= 3 ? now()->addMinutes(5) : null,
                    'updated_at' => now(),
                ]);
        }, 3);
    }
}
