<?php

namespace App\Modules\AI\Infrastructure;

use App\Modules\AI\Domain\Contracts\BoundedAutonomySafetySnapshotSource;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;

/**
 * Independently persisted, tenant-scoped offline autonomy counters/stops.
 * No row is an automatic permission: missing global or workspace rows deny.
 * Final reservations must re-read these rows under transaction locks.
 */
final class DatabaseBoundedAutonomySafetySnapshotSource implements BoundedAutonomySafetySnapshotSource
{
    public function current(TenantContext $scope, DateTimeImmutable $at): ?array
    {
        $date = $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d');
        $global = DB::table('ai_autonomy_global_stops')->where('id', 'global')->first();
        $quota = DB::table('ai_autonomy_workspace_quotas')
            ->where('workspace_id', $scope->workspaceId)
            ->where('period_utc', $date)->first();

        if ($global === null || $quota === null) {
            return null;
        }

        $expires = (new DateTimeImmutable((string) $quota->policy_expires_at, new DateTimeZone('UTC')))
            ->getTimestamp();
        if ($expires <= $at->getTimestamp()) {
            return null;
        }

        return [
            'workspace_id' => $scope->workspaceId,
            'brand_id' => $scope->brandId,
            'policy_version' => (string) $quota->policy_version,
            'observed_at_unix' => $at->getTimestamp(),
            'expires_at_unix' => min($expires, $at->getTimestamp() + 300),
            'global_stopped' => (bool) $global->stopped,
            'workspace_stopped' => (bool) $quota->workspace_stopped,
            'max_actions' => (int) $quota->max_actions,
            'max_tokens' => (int) $quota->max_tokens,
            'max_volume' => (int) $quota->max_volume,
            'max_cost_minor' => (int) $quota->max_cost_minor,
            'max_attempts' => (int) $quota->max_attempts,
            'used_actions' => (int) $quota->used_actions,
            'used_tokens' => (int) $quota->used_tokens,
            'used_volume' => (int) $quota->used_volume,
            'reserved_cost_minor' => (int) $quota->reserved_cost_minor,
            'spent_cost_minor' => (int) $quota->spent_cost_minor,
            'used_attempts' => (int) $quota->used_attempts,
        ];
    }
}
